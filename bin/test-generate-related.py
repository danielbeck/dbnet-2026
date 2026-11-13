import importlib.util
import io
import json
import os
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError
import warnings

import numpy as np


spec = importlib.util.spec_from_file_location("generate_related", Path(__file__).with_name("generate-related.py"))
generator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(generator)


def create_site(path):
    connection = sqlite3.connect(path)
    connection.execute("CREATE TABLE pool_items (id TEXT PRIMARY KEY, title TEXT NOT NULL, "
                       "body TEXT NOT NULL, format TEXT NOT NULL, date INTEGER NOT NULL)")
    connection.executemany("INSERT INTO pool_items VALUES (?, ?, ?, ?, ?)", [
        ("a", "Alpha", "First", "html", 0), ("b", "Beta", "Second", "html", 0),
        ("c", "Gamma", "Third", "markdown", 0), ("empty", "Empty", "## Heading", "markdown", 0),
    ])
    connection.commit()
    return connection


class RelatedTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.database = Path(self.directory.name) / "site.sqlite"
        self.cache = Path(self.directory.name) / "cache.sqlite"
        self.site = create_site(self.database)
        self.addCleanup(self.site.close)

    def test_text_body_and_hash(self):
        self.assertEqual(generator.embedding_text("Title", "A\r\nB", 0),
                         "# Title\n\n## January 1, 1970\n\nA\r\nB")
        self.assertFalse(generator.has_body(" \n # Heading\n## Another"))
        self.assertTrue(generator.has_body("## Heading\nText"))
        self.assertEqual(generator.site_hash("a", "b", "c"),
                         "8badde10c760e9b702defb4b5e225de79c515b1d2a5cfb000e140f3c6fbb5629")

    def test_http_request_and_errors(self):
        class Response:
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def read(self):
                return b'{"embeddings":[[1,2]]}'

        with patch.object(generator, "urlopen", return_value=Response()) as urlopen:
            np.testing.assert_array_equal(generator.request_embedding("text", "http://host", "model", 1), [1, 2])
            payload = json.loads(urlopen.call_args.args[0].data)
            self.assertEqual(payload["input"], "text")
            self.assertFalse(payload["truncate"])
            self.assertEqual(payload["options"]["num_ctx"], 32768)
        error = HTTPError("url", 400, "bad", {}, io.BytesIO(b"input length exceeds the context length"))
        with patch.object(generator, "urlopen", side_effect=error):
            with self.assertRaises(generator.ContextLengthError):
                generator.request_embedding("text", "http://host", "model", 1)
        for value in ([], [0, 0], [1, float("nan")], [True], [[1]]):
            with self.subTest(value=value), self.assertRaises(ValueError):
                generator.validate_vector(value)

    def test_context_fallback_covers_complete_document(self):
        text = "a" * 2500 + "\n\n" + "b" * 2400
        parts = list(generator.chunks(text))
        self.assertTrue(parts[0].startswith(text[:200]))
        self.assertTrue(parts[-1].endswith(text[-200:]))
        self.assertEqual("".join(part if i == 0 else part[200:]
                                 for i, part in enumerate(parts)), text)
        def fake_request(value, *_args):
            if value == text:
                raise generator.ContextLengthError("too long")
            return np.array([3.0, 4.0])
        with patch.object(generator, "request_embedding", side_effect=fake_request):
            method, vector = generator.embed_document(text, "host", "model", 1)
        self.assertEqual(method, "chunk_average")
        np.testing.assert_allclose(vector, [0.6, 0.8])

    def test_cache_reuses_only_matching_model_digest_and_input(self):
        posts = generator.read_posts(self.site)
        cache = sqlite3.connect(self.cache)
        self.addCleanup(cache.close)
        cache.execute(generator.CACHE_SCHEMA)
        with patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))) as embed, \
             patch.object(generator, "model_digest", side_effect=lambda _host, _model, _timeout: digest[0]):
            digest = ["digest1"]
            _, _, counts = generator.embeddings_for_posts(posts, cache, "host", "model", "digest1", 1)
            self.assertEqual((counts["generated"], counts["skipped"]), (3, 1))
            generator.embeddings_for_posts(posts, cache, "host", "model", "digest1", 1)
            self.assertEqual(embed.call_count, 3)
            digest[0] = "digest2"
            generator.embeddings_for_posts(posts, cache, "host", "model", "digest2", 1)
            self.assertEqual(embed.call_count, 6)
            self.site.execute("UPDATE pool_items SET body='Changed' WHERE id='a'")
            self.site.commit()
            digest[0] = "digest1"
            generator.embeddings_for_posts(generator.read_posts(self.site), cache, "host", "model", "digest1", 1)
            self.assertEqual(embed.call_count, 7)

    def test_scan_agreement_ranking_and_no_transitive_links(self):
        ids = ["a", "b", "c", "d", "noise"]
        vectors = [np.array([1., 0]), np.array([0.6, 0.8]), np.array([0.9, 0.1]),
                   np.array([0.99, 0.01]), np.array([0., 1])]
        labels = [np.array([0, 0, 0, -1, -1]),
                  np.array([0, 0, -1, 0, -1]),
                  np.array([-1, 0, 0, 0, -1])]
        with patch.object(generator.HDBSCAN, "fit_predict", side_effect=labels):
            links, votes, scans = generator.make_links(ids, vectors, 3, 3, 2, 4, 10)
        a = [(target, rank) for source, target, rank, _ in links if source == "a"]
        self.assertEqual(a, [("b", 1), ("d", 2), ("c", 3)])
        self.assertEqual(votes[("a", "b")], 2)
        self.assertEqual(votes[("a", "d")], 1)
        self.assertNotIn(("a", "noise"), votes)
        self.assertEqual(len(scans), 3)
        self.assertEqual([scan["candidate_clusters"] for scan in scans], [1, 1, 1])

    def test_exact_cluster_bounds_noise_and_limit(self):
        ids = [str(i).zfill(2) for i in range(25)]
        vectors = [np.array([1., 0])] * 25
        labels = np.array([0] * 2 + [1] * 3 + [2] * 10 + [3] * 10)
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=labels):
            links, votes, _ = generator.make_links(ids, vectors, 3, 3, 2, 2, 10)
        self.assertEqual(len(links), 186)
        self.assertNotIn(("00", "01"), votes)
        self.assertIn(("05", "14"), votes)
        self.assertEqual(next(link for link in links if link[0] == "05")[:3], ("05", "06", 1))
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=labels):
            links, _, _ = generator.make_links(ids, vectors, 3, 3, 2, 2, 1)
        self.assertEqual(len(links), 23)
        labels = np.array([0] * 11 + [-1] * 14)
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=labels):
            links, votes, _ = generator.make_links(ids, vectors, 3, 3, 2, 2, 10)
        self.assertEqual((links, votes), ([], {}))

    def test_invalid_scan_ranges(self):
        vectors = [np.array([1., 0])] * 3
        for ranges in ((2, 3, 2, 2), (3, 11, 2, 2), (4, 3, 2, 2),
                       (3, 3, 3, 2), (3, 3, 0, 2), (3, 4, 2, 2)):
            with self.subTest(ranges=ranges), self.assertRaises(ValueError):
                generator.make_links(["a", "b", "c"], vectors, *ranges, 10)

    def test_real_hdbscan_scan_has_no_copy_future_warning(self):
        ids = ["a", "b", "c", "d", "e", "f"]
        vectors = [np.array([1., x]) for x in (0., 0.01, 0.02, 1., 1.01, 1.02)]
        with warnings.catch_warnings():
            warnings.filterwarnings("error", message=".*default value of `copy`.*", category=FutureWarning)
            links, _, scans = generator.make_links(ids, vectors, 3, 3, 2, 2, 10)
        self.assertEqual(len(scans), 1)
        self.assertTrue(all(source != target for source, target, _, _ in links))

    def test_hierarchy_cache_reuses_one_fit_per_sample_setting(self):
        ids = [str(i) for i in range(8)]
        vectors = [np.array([1., x]) for x in (0., 0.01, 0.02, 0.03, 1., 1.01, 1.02, 1.03)]
        with tempfile.TemporaryDirectory() as directory:
            memory = generator.Memory(location=directory, verbose=0)
            generator.make_links(ids, vectors, 3, 5, 2, 3, 10, memory=memory)
            cached_hierarchies = list(Path(directory).rglob("output.pkl"))
            self.assertEqual(len(cached_hierarchies), 2)

    def test_sample_translation_and_single_sample_fallback(self):
        ids = ["a", "b", "c"]
        vectors = [np.array([1., x]) for x in (0., 0.1, 0.2)]
        with patch.object(generator, "HDBSCAN") as contrib, \
             patch.object(generator, "SklearnHDBSCAN") as sklearn:
            contrib.return_value.fit_predict.return_value = np.array([0, 0, 0])
            sklearn.return_value.fit_predict.return_value = np.array([0, 0, 0])
            generator.make_links(ids, vectors, 3, 3, 1, 3, 10)
        sklearn.assert_called_once()
        self.assertEqual(sklearn.call_args.kwargs["min_samples"], 1)
        self.assertEqual([call.kwargs["min_samples"] for call in contrib.call_args_list], [1, 2])
        self.assertTrue(all(call.kwargs["algorithm"] == "generic" for call in contrib.call_args_list))

    def test_cluster_cache_reuse_corpus_and_version_invalidation(self):
        cache = sqlite3.connect(self.cache)
        self.addCleanup(cache.close)
        cache.execute(generator.CLUSTER_CACHE_SCHEMA)
        ids = ["a", "b", "c", "d"]
        vectors = [np.array([1., x]) for x in (0., 0.1, 0.2, 0.3)]
        labels = np.array([0, 0, 0, -1])
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=labels) as fit:
            first = generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 2)
            cached = generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(cached, first)
            self.assertEqual(fit.call_count, 2)
            generator.make_links(ids, vectors, 3, 3, 2, 4, 10, cache)
            self.assertEqual(fit.call_count, 3)
            generator.make_links(ids, vectors, 3, 3, 2, 3, 1, cache)
            self.assertEqual(fit.call_count, 3)
            generator.make_links(["changed"] + ids[1:], vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 5)
            changed = list(vectors)
            changed[0] = np.array([1., 0.001])
            generator.make_links(ids, changed, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 7)
            with patch.object(generator.sklearn, "__version__", "different"):
                generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 9)
            with patch.object(generator, "CLUSTER_METHOD_VERSION", "different"):
                generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 11)
            original_version = generator.version
            with patch.object(generator, "version",
                              side_effect=lambda name: "different" if name == "hdbscan" else original_version(name)):
                generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 13)

    def test_cluster_cache_resumes_after_interruption(self):
        cache = sqlite3.connect(self.cache)
        self.addCleanup(cache.close)
        cache.execute(generator.CLUSTER_CACHE_SCHEMA)
        ids = ["a", "b", "c", "d"]
        vectors = [np.array([1., x]) for x in (0., 0.1, 0.2, 0.3)]
        labels = np.array([0, 0, 0, -1])
        with patch.object(generator.HDBSCAN, "fit_predict",
                          side_effect=[labels, RuntimeError("interrupted")]):
            with self.assertRaisesRegex(RuntimeError, "interrupted"):
                generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
        self.assertEqual(cache.execute("SELECT COUNT(*) FROM cluster_results").fetchone()[0], 1)
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=labels) as fit:
            generator.make_links(ids, vectors, 3, 3, 2, 3, 10, cache)
            self.assertEqual(fit.call_count, 1)

    def test_cluster_cache_rejects_bad_labels(self):
        cache = sqlite3.connect(self.cache)
        self.addCleanup(cache.close)
        cache.execute(generator.CLUSTER_CACHE_SCHEMA)
        ids = ["a", "b", "c"]
        vectors = [np.array([1., x]) for x in (0., 0.1, 0.2)]
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=np.array([0, 0, 0])):
            generator.make_links(ids, vectors, 3, 3, 2, 2, 10, cache)
        for encoded in ('not-json', '[0, 0]', '[0, 0, true]', '[0, 0, 3]'):
            with self.subTest(encoded=encoded):
                cache.execute("UPDATE cluster_results SET labels_json=?", (encoded,))
                cache.commit()
                with self.assertRaisesRegex(ValueError, "Invalid cached HDBSCAN result"):
                    generator.make_links(ids, vectors, 3, 3, 2, 2, 10, cache)

    def test_overlapping_clusters_do_not_create_indirect_pairs(self):
        ids = ["a", "b", "c", "d", "e"]
        vectors = [np.array([1., 0])] * 5
        labels = [np.array([0, 0, 0, -1, -1]), np.array([-1, -1, 0, 0, 0])]
        with patch.object(generator.HDBSCAN, "fit_predict", side_effect=labels):
            _, agreement, _ = generator.make_links(ids, vectors, 3, 3, 2, 3, 10)
        self.assertIn(("a", "c"), agreement)
        self.assertIn(("c", "d"), agreement)
        self.assertNotIn(("a", "d"), agreement)

    def test_cluster_report_deduplicates_memberships_and_reuses_cached_fits(self):
        labels = [np.array([0, 0, 0]), np.array([1, 1, 1])]
        self.site.execute("UPDATE pool_items SET title='Same' WHERE id IN ('a', 'b')")
        self.site.commit()
        with patch.object(generator, "model_digest", return_value="abc"), \
             patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))), \
             patch.object(generator.HDBSCAN, "fit_predict", side_effect=labels) as fit:
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10,
                                   False, max_minimum=3, max_samples=3, include_clusters=True)
            self.assertEqual(fit.call_count, 2)
        with patch.object(generator, "model_digest", return_value="abc"), \
             patch.object(generator.HDBSCAN, "fit_predict", side_effect=AssertionError("cache missed")):
            cached = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10,
                                   False, max_minimum=3, max_samples=3, include_clusters=True)
        self.assertEqual(result["candidate_clusters"], cached["candidate_clusters"])
        self.assertEqual(result["proposals"], cached["proposals"])
        self.assertEqual(len(result["candidate_clusters"]), 1)
        cluster = result["candidate_clusters"][0]
        self.assertEqual([x["id"] for x in cluster["members"]], ["a", "b", "c"])
        self.assertEqual(cluster["settings"], [{"min_cluster_size": 3, "min_samples": 2},
                                               {"min_cluster_size": 3, "min_samples": 3}])
        report = generator.format_cluster_report(result)
        self.assertIn('"Same" [id: a]', report)
        self.assertIn('"Same" [id: b]', report)
        self.assertIn('"Gamma"', report)
        self.assertIn("3/2, 3/3", report)

    def test_report_output_modes_preserve_json_and_snapshot(self):
        result = {"candidate_clusters": [{"members": [
            {"id": "a", "title": "First"}, {"id": "b", "title": "Second"},
            {"id": "c", "title": "Third"}],
            "settings": [{"min_cluster_size": 3, "min_samples": 2}]}],
            "links": 2, "published": False, "snapshot": [{"id": "a"}]}
        for flag in ("--clusters", "--show-clusters"):
            out, err = io.StringIO(), io.StringIO()
            with self.subTest(flag=flag), patch.object(sys, "argv", ["generator", flag]), \
                 patch.object(generator, "run", return_value=dict(result)), \
                 redirect_stdout(out), redirect_stderr(err):
                self.assertEqual(generator.main(), 0)
            if flag == "--clusters":
                self.assertIn('"First"', out.getvalue())
                self.assertEqual(err.getvalue(), "")
            else:
                self.assertIn('"First"', err.getvalue())
                payload = json.loads(out.getvalue())
                self.assertEqual(payload["snapshot"], [{"id": "a"}])
                self.assertNotIn("candidate_clusters", payload)

    def test_link_report_uses_titles_and_rank_order(self):
        result = {"link_titles": {"a": "Same", "b": "Same", "c": "Other\nTitle", "d": "Unused"},
                  "links": 2, "published": True, "proposals": [
                      {"source_id": "a", "target_id": "c", "rank": 2, "agreement": 2, "similarity": 0.6},
                      {"source_id": "a", "target_id": "b", "rank": 1, "agreement": 4, "similarity": 0.7}]}
        report = generator.format_link_report(result)
        self.assertIn("published locally", report)
        self.assertIn('"Same" [id: a]', report)
        self.assertIn('1. "Same" [id: b] (agreement: 4, similarity: 0.700)', report)
        self.assertIn('2. "Other\\nTitle" (agreement: 2, similarity: 0.600)', report)
        self.assertLess(report.index('1. "Same"'), report.index('2. "Other'))
        self.assertNotIn("Unused", report)

    def test_link_report_modes_keep_titles_out_of_json(self):
        result = {"link_titles": {"a": "First", "b": "Second"}, "links": 1, "published": False,
                  "proposals": [{"source_id": "a", "target_id": "b", "rank": 1,
                                 "agreement": 3, "similarity": 0.75}],
                  "snapshot": [{"id": "a"}]}
        for flag in ("--links", "--show-links"):
            out, err = io.StringIO(), io.StringIO()
            with self.subTest(flag=flag), patch.object(sys, "argv", ["generator", flag]), \
                 patch.object(generator, "run", return_value=dict(result)), \
                 redirect_stdout(out), redirect_stderr(err):
                self.assertEqual(generator.main(), 0)
            if flag == "--links":
                self.assertIn('"First"', out.getvalue())
                self.assertIn('"Second"', out.getvalue())
                self.assertEqual(err.getvalue(), "")
            else:
                self.assertIn('"Second"', err.getvalue())
                payload = json.loads(out.getvalue())
                self.assertEqual(payload["snapshot"], [{"id": "a"}])
                self.assertNotIn("link_titles", payload)

        report_file = Path(self.directory.name) / "report.txt"
        out = io.StringIO()
        with patch.object(sys, "argv", ["generator", "--include-snapshot", "--link-report", str(report_file)]), \
             patch.object(generator, "run", return_value=dict(result)), redirect_stdout(out):
            self.assertEqual(generator.main(), 0)
        self.assertIn('"Second"', report_file.read_text())
        self.assertNotIn("link_titles", json.loads(out.getvalue()))

    def test_link_report_refuses_to_overwrite_site_or_cache(self):
        for target in (self.database, self.cache):
            with self.subTest(target=target), \
                 patch.object(sys, "argv", ["generator", "--database", str(self.database),
                                             "--cache", str(self.cache), "--link-report", str(target)]), \
                 redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as error:
                generator.main()
            self.assertEqual(error.exception.code, 2)

    def test_preview_reads_wal_database_without_wal_sidecars(self):
        self.site.execute("PRAGMA journal_mode=WAL")
        self.site.close()
        with patch.object(generator, "model_digest", return_value="abc"), \
             patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))), \
             patch.object(generator, "make_links", return_value=([], {}, [])):
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, False)
        self.assertEqual(result["posts"], 4)
        check = sqlite3.connect(self.database)
        try:
            self.assertEqual(check.execute(
                "SELECT count(*) FROM sqlite_master WHERE name='related_items'").fetchone()[0], 0)
        finally:
            check.close()

    def test_digest_change_does_not_cache_embedding(self):
        cache = sqlite3.connect(self.cache)
        self.addCleanup(cache.close)
        cache.execute(generator.CACHE_SCHEMA)
        with patch.object(generator, "model_digest", return_value="changed"), \
             patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))):
            with self.assertRaisesRegex(RuntimeError, "digest changed"):
                generator.embeddings_for_posts(generator.read_posts(self.site), cache, "host", "model", "original", 1)
        self.assertEqual(cache.execute("SELECT COUNT(*) FROM embeddings").fetchone()[0], 0)

    def test_cache_cannot_be_site_database(self):
        with self.assertRaisesRegex(ValueError, "different files"):
            generator.run(self.database, self.database, "http://host", "model", 1, 3, 2, 10, True)

    def test_snapshot_output_is_opt_in(self):
        with patch.object(generator, "model_digest", return_value="abc"), \
             patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))), \
             patch.object(generator, "make_links", return_value=([], {}, [])):
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, False, True)
        self.assertEqual(len(result["snapshot"]), 4)
        self.assertEqual(result["snapshot"][0], {
            "id": "a", "hash": generator.site_hash("Alpha", "First", "html"), "date": 0})

    def test_publish_checks_snapshot_and_rolls_back(self):
        posts = generator.read_posts(self.site)
        generator.publish_links(self.site, posts, [("a", "b", 1, 0.5)])
        before = self.site.execute("SELECT * FROM related_items").fetchall()
        with self.assertRaises(sqlite3.IntegrityError):
            generator.publish_links(self.site, posts, [("a", "b", 1, 0.5), ("a", "c", 1, 0.6)])
        self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)
        self.site.execute("UPDATE pool_items SET date=1 WHERE id='c'")
        self.site.commit()
        with self.assertRaisesRegex(ValueError, "changed"):
            generator.publish_links(self.site, posts, [])
        self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)
        self.site.execute("DELETE FROM pool_items WHERE id='empty'")
        self.site.commit()
        with self.assertRaisesRegex(ValueError, "changed"):
            generator.publish_links(self.site, posts, [])

    def test_run_preview_cache_and_digest_change(self):
        with patch.object(generator, "model_digest", return_value="abc"), \
             patch.object(generator, "embed_document", return_value=("full_document", np.array([1., 2.]))), \
             patch.object(generator, "make_links", return_value=([("a", "b", 1, 0.5)], {("a", "b"): 2}, [])):
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, False)
            self.assertEqual(result["generated"], 3)
            self.assertEqual(self.site.execute("SELECT count(*) FROM sqlite_master WHERE name='related_items'").fetchone()[0], 0)
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, True)
            self.assertEqual(result["cached"], 3)
            self.assertEqual(self.site.execute("SELECT count(*) FROM related_items").fetchone()[0], 1)
        with patch.object(generator, "model_digest", side_effect=["abc", "changed"]), \
             patch.object(generator, "make_links", return_value=([], {}, [])):
            with self.assertRaisesRegex(ValueError, "digest changed"):
                generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, True)
        self.assertEqual(self.site.execute("SELECT count(*) FROM related_items").fetchone()[0], 1)


@unittest.skipUnless(shutil.which("php"), "PHP CLI is required")
class LivePublishTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / "storage").mkdir()
        self.database = self.root / "storage" / "dbnet.sqlite"
        self.site = create_site(self.database)
        self.addCleanup(self.site.close)
        self.posts = generator.read_posts(self.site)
        self.payload = {
            "published": False,
            "posts": len(self.posts),
            "links": 1,
            "snapshot": [{"id": identifier, "hash": generator.site_hash(title, body, fmt), "date": date}
                         for identifier, title, body, fmt, date in self.posts],
            "proposals": [{"source_id": "a", "target_id": "b", "rank": 1, "similarity": 0.75}],
        }
        self.apply = Path(__file__).with_name("apply-related-live.php")

    def apply_payload(self, payload):
        return subprocess.run(["php", str(self.apply), str(self.database)], input=json.dumps(payload),
                              text=True, capture_output=True)

    def test_publish_and_reject_changed_posts_without_modifying_links(self):
        result = self.apply_payload(self.payload)
        self.assertEqual(result.returncode, 0, result.stderr)
        before = self.site.execute("SELECT * FROM related_items").fetchall()
        self.assertEqual(len(before), 1)
        for field, value in (("body", "edited"), ("date", 10), ("format", "html")):
            with self.subTest(field=field):
                original = self.site.execute("SELECT " + field + " FROM pool_items WHERE id='c'").fetchone()[0]
                self.site.execute("UPDATE pool_items SET " + field + "=? WHERE id='c'", (value,))
                self.site.commit()
                result = self.apply_payload(self.payload)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Live posts differ", result.stderr)
                self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)
                self.site.execute("UPDATE pool_items SET " + field + "=? WHERE id='c'", (original,))
                self.site.commit()
        self.site.execute("DELETE FROM pool_items WHERE id='empty'")
        self.site.commit()
        self.assertNotEqual(self.apply_payload(self.payload).returncode, 0)
        self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)

    def test_invalid_link_rolls_back_replacement(self):
        self.assertEqual(self.apply_payload(self.payload).returncode, 0)
        before = self.site.execute("SELECT * FROM related_items").fetchall()
        self.payload["proposals"].append({"source_id": "a", "target_id": "c", "rank": 1, "similarity": 0.9})
        self.payload["links"] = 2
        self.assertNotEqual(self.apply_payload(self.payload).returncode, 0)
        self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)

    def test_incomplete_payload_does_not_replace_links(self):
        self.assertEqual(self.apply_payload(self.payload).returncode, 0)
        before = self.site.execute("SELECT * FROM related_items").fetchall()
        self.payload["proposals"] = []
        self.assertNotEqual(self.apply_payload(self.payload).returncode, 0)
        self.assertEqual(self.site.execute("SELECT * FROM related_items").fetchall(), before)

    def test_ssh_transport_executes_only_against_test_database(self):
        fake_bin = self.root / "fake-bin"
        fake_bin.mkdir()
        ssh = fake_bin / "ssh"
        ssh.write_text('#!/bin/sh\n[ "$1" = test-host ] || exit 1\nexec /bin/sh -c "$2"\n')
        ssh.chmod(0o755)
        snapshot = self.root / "snapshot.json"
        snapshot.write_text(json.dumps(self.payload))
        env = os.environ.copy()
        env["PATH"] = str(fake_bin) + os.pathsep + env["PATH"]
        script = Path(__file__).with_name("publish-related-live.php")
        result = subprocess.run(["php", str(script), "test-host:" + str(self.root),
                                 shutil.which("php"), str(snapshot)], env=env, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.site.execute("SELECT count(*) FROM related_items").fetchone()[0], 1)


if __name__ == "__main__":
    unittest.main()
