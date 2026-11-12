import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError

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

    def test_same_cluster_ranking_and_noise(self):
        ids = ["a", "b", "c", "noise"]
        vectors = [np.array([1., 0]), np.array([0.9, 0.1]),
                   np.array([0.9, 0.1]), np.array([0., 1])]
        with patch.object(generator.HDBSCAN, "fit_predict", return_value=np.array([0, 0, 0, -1])):
            links, groups, noise = generator.make_links(ids, vectors, 3, 2, 1)
        self.assertEqual((groups, noise, len(links)), (1, 1, 3))
        self.assertEqual(links[0][:3], ("a", "b", 1))
        self.assertTrue(all(source != "noise" and target != "noise" for source, target, _, _ in links))

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
             patch.object(generator, "make_links", return_value=([], 0, 3)):
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
             patch.object(generator, "make_links", return_value=([("a", "b", 1, 0.5)], 1, 0)):
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, False)
            self.assertEqual(result["generated"], 3)
            self.assertEqual(self.site.execute("SELECT count(*) FROM sqlite_master WHERE name='related_items'").fetchone()[0], 0)
            result = generator.run(self.database, self.cache, "http://host", "model", 1, 3, 2, 10, True)
            self.assertEqual(result["cached"], 3)
            self.assertEqual(self.site.execute("SELECT count(*) FROM related_items").fetchone()[0], 1)
        with patch.object(generator, "model_digest", side_effect=["abc", "changed"]), \
             patch.object(generator, "make_links", return_value=([], 0, 3)):
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
