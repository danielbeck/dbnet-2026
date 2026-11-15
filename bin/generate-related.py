#!/usr/bin/env python3
"""Generate or publish related pool items using a local Ollama server."""

import argparse
from bisect import bisect_right
from collections import Counter
from datetime import datetime, timezone
import hashlib
from importlib.metadata import version
import json
import math
import os
import re
import sqlite3
import sys
import tempfile
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

import numpy as np
from hdbscan import HDBSCAN
from joblib import Memory
import sklearn
from sklearn.cluster import HDBSCAN as SklearnHDBSCAN


ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DATABASE = ROOT / "storage" / "dbnet.sqlite"
DEFAULT_CACHE = ROOT / "storage" / "related-embeddings.sqlite"
DEFAULT_HOST = "http://192.168.0.98:11434"
DEFAULT_MODEL = "qwen3-embedding:0.6b"
METHOD_VERSION = "site-document-v1-ctx32768-chunks2000-overlap200"
CLUSTER_METHOD_VERSION = "contrib-hdbscan-generic-euclidean-eom-self-inclusive-v1"
RELATED_SCHEMA = """
CREATE TABLE IF NOT EXISTS related_items (
    source_id TEXT NOT NULL REFERENCES pool_items(id) ON DELETE CASCADE,
    target_id TEXT NOT NULL REFERENCES pool_items(id) ON DELETE CASCADE,
    rank INTEGER NOT NULL CHECK(rank > 0),
    similarity REAL NOT NULL,
    source_hash TEXT NOT NULL,
    target_hash TEXT NOT NULL,
    PRIMARY KEY (source_id, target_id),
    UNIQUE (source_id, rank),
    CHECK(source_id != target_id)
)
"""
CACHE_SCHEMA = """
CREATE TABLE IF NOT EXISTS embeddings (
    model TEXT NOT NULL, digest TEXT NOT NULL, method_version TEXT NOT NULL,
    input_hash TEXT NOT NULL, method TEXT NOT NULL, vector TEXT NOT NULL,
    PRIMARY KEY (model, digest, method_version, input_hash)
)
"""
CLUSTER_CACHE_SCHEMA = """
CREATE TABLE IF NOT EXISTS cluster_results (
    corpus_hash TEXT NOT NULL, sklearn_version TEXT NOT NULL,
    method_version TEXT NOT NULL, min_cluster_size INTEGER NOT NULL,
    min_samples INTEGER NOT NULL, labels_json TEXT NOT NULL,
    PRIMARY KEY (corpus_hash, sklearn_version, method_version, min_cluster_size, min_samples)
)
"""


class ContextLengthError(RuntimeError):
    pass


def http_json(host, path, timeout, payload=None):
    request = Request(host.rstrip("/") + path,
                      data=None if payload is None else json.dumps(payload).encode("utf-8"),
                      headers={"Content-Type": "application/json"})
    try:
        with urlopen(request, timeout=timeout) as response:
            result = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")
        if error.code == 400 and "input length exceeds the context length" in detail:
            raise ContextLengthError("Ollama HTTP 400: " + detail) from error
        raise RuntimeError("Ollama HTTP {}: {}".format(error.code, detail)) from error
    except (URLError, OSError) as error:
        raise RuntimeError("Cannot reach Ollama: {}".format(error)) from error
    except (ValueError, UnicodeDecodeError) as error:
        raise RuntimeError("Ollama returned invalid JSON") from error
    if not isinstance(result, dict) or "error" in result:
        raise RuntimeError("Invalid Ollama response: {}".format(result))
    return result


def model_digest(host, model, timeout):
    result = http_json(host, "/api/tags", timeout)
    models = result.get("models")
    if not isinstance(models, list):
        raise RuntimeError("Ollama did not return a model list")
    matches = [item for item in models if isinstance(item, dict)
               and item.get("name") == model]
    if len(matches) != 1 or not isinstance(matches[0].get("digest"), str) or not matches[0]["digest"]:
        raise RuntimeError("Model {} is not available with a unique digest on Ollama; pull it first".format(model))
    return matches[0]["digest"]


def validate_vector(values):
    if (not isinstance(values, list) or not values
            or any(isinstance(v, bool) or not isinstance(v, (int, float))
                   or not math.isfinite(v) for v in values)):
        raise ValueError("Embedding must be a nonempty vector of finite numbers")
    vector = np.asarray(values, dtype=np.float64)
    norm = np.linalg.norm(vector)
    if not np.isfinite(norm) or norm == 0:
        raise ValueError("Embedding must have a finite, nonzero norm")
    return vector


def request_embedding(text, host, model, timeout):
    result = http_json(host, "/api/embed", timeout, {
        "model": model, "input": text, "truncate": False,
        "options": {"num_ctx": 32768},
    })
    vectors = result.get("embeddings")
    if not isinstance(vectors, list) or len(vectors) != 1:
        raise RuntimeError("Ollama must return exactly one embedding")
    return validate_vector(vectors[0])


def chunks(text, size=2000, overlap=200):
    boundaries = [match.end() for match in re.finditer(r"\r?\n[ \t]*\r?\n", text)]
    start = 0
    while start < len(text):
        end = min(start + size, len(text))
        if end < len(text):
            index = bisect_right(boundaries, end) - 1
            if index >= 0 and boundaries[index] > start + overlap:
                end = boundaries[index]
        yield text[start:end]
        if end == len(text):
            break
        start = end - overlap


def embed_document(text, host, model, timeout):
    try:
        return "full_document", request_embedding(text, host, model, timeout)
    except ContextLengthError:
        print("Context exceeded; embedding all overlapping chunks", file=sys.stderr, flush=True)
    vectors = [request_embedding(chunk, host, model, timeout) for chunk in chunks(text)]
    if not vectors or len({len(vector) for vector in vectors}) != 1:
        raise ValueError("Chunk embeddings have inconsistent dimensions")
    mean = np.array([math.fsum(values) / len(vectors) for values in zip(*vectors)])
    return "chunk_average", validate_vector(mean.tolist()) / np.linalg.norm(mean)


def has_body(body):
    return any(line.strip() and not re.match(r"^#{1,6}\s+", line.strip())
               for line in body.splitlines())


def embedding_text(title, body, date):
    day = datetime.fromtimestamp(date / 1000, timezone.utc)
    months = ("January", "February", "March", "April", "May", "June", "July",
              "August", "September", "October", "November", "December")
    return "# {}\n\n## {} {}, {}\n\n{}".format(title, months[day.month - 1], day.day, day.year, body)


def site_hash(title, body, fmt):
    return hashlib.sha256("\0".join((title, body, fmt)).encode("utf-8")).hexdigest()


def read_posts(connection):
    rows = connection.execute(
        "SELECT id, title, body, format, date FROM pool_items ORDER BY id"
    ).fetchall()
    if not rows:
        raise ValueError("The site database contains no pool items")
    for identifier, title, body, fmt, date in rows:
        if (not all(isinstance(value, str) for value in (identifier, title, body, fmt))
                or not isinstance(date, int)):
            raise ValueError("Invalid content or date for post {!r}".format(identifier))
    return rows


def embeddings_for_posts(posts, cache, host, model, digest, timeout):
    ids, vectors = [], []
    counts = {"generated": 0, "cached": 0, "skipped": 0, "chunk_average": 0}
    for identifier, title, body, _fmt, date in posts:
        if not has_body(body):
            counts["skipped"] += 1
            continue
        text = embedding_text(title, body, date)
        input_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
        key = (model, digest, METHOD_VERSION, input_hash)
        row = cache.execute("SELECT method, vector FROM embeddings WHERE model=? AND digest=? "
                            "AND method_version=? AND input_hash=?", key).fetchone()
        if row:
            method, encoded = row
            if method not in ("full_document", "chunk_average"):
                raise ValueError("Invalid cached embedding method for {}".format(identifier))
            vector = validate_vector(json.loads(encoded))
            counts["cached"] += 1
        else:
            try:
                method, vector = embed_document(text, host, model, timeout)
                if model_digest(host, model, timeout) != digest:
                    raise ValueError("Ollama model digest changed during embedding")
            except (RuntimeError, ValueError) as error:
                raise RuntimeError("Post {}: {}".format(identifier, error)) from error
            with cache:
                cache.execute("INSERT OR REPLACE INTO embeddings VALUES (?,?,?,?,?,?)",
                              (*key, method, json.dumps(vector.tolist(), allow_nan=False)))
            counts["generated"] += 1
            print("Embedded {} ({}; generated {})".format(identifier, method, counts["generated"]),
                  file=sys.stderr, flush=True)
        if method == "chunk_average":
            counts["chunk_average"] += 1
        if vectors and len(vector) != len(vectors[0]):
            raise ValueError("Inconsistent embedding dimensions for post {}".format(identifier))
        ids.append(identifier)
        vectors.append(vector)
    return ids, vectors, counts


def corpus_fingerprint(ids, matrix):
    digest = hashlib.sha256()
    digest.update(np.asarray(matrix.shape, dtype="<i8").tobytes())
    for identifier in ids:
        encoded = identifier.encode("utf-8")
        digest.update(len(encoded).to_bytes(8, "little"))
        digest.update(encoded)
    digest.update(np.ascontiguousarray(matrix, dtype="<f8").tobytes())
    return digest.hexdigest()


def validate_labels(labels, expected):
    if (not isinstance(labels, list) or len(labels) != expected
            or any(isinstance(label, bool) or not isinstance(label, int)
                   or label < -1 or label >= expected for label in labels)):
        raise ValueError("Invalid cached or computed HDBSCAN labels")
    return np.asarray(labels, dtype=np.int64)


def make_links(ids, vectors, minimum, max_minimum, samples, max_samples, limit, cache=None,
               cluster_settings=None, memory=None):
    if not 3 <= minimum <= max_minimum <= 10 or not 1 <= samples <= max_samples:
        raise ValueError("Require 3 <= min-cluster-size <= max-cluster-size <= 10 "
                         "and 1 <= min-samples <= max-samples")
    if len(ids) < max(max_minimum, max_samples):
        raise ValueError("Not enough substantive posts for the selected HDBSCAN scan ranges")
    matrix = np.vstack(vectors)
    fingerprint = corpus_fingerprint(ids, matrix)
    normalized = matrix / np.linalg.norm(matrix, axis=1)[:, None]
    votes = {}
    scans = []
    total = (max_minimum - minimum + 1) * (max_samples - samples + 1)
    backend_version = "hdbscan={};sklearn={};numpy={};scipy={};joblib={}".format(
        version("hdbscan"), sklearn.__version__, np.__version__, version("scipy"), version("joblib"))
    for size in range(minimum, max_minimum + 1):
        for sample_count in range(samples, max_samples + 1):
            key = (fingerprint, backend_version, CLUSTER_METHOD_VERSION, size, sample_count)
            row = None if cache is None else cache.execute(
                "SELECT labels_json FROM cluster_results WHERE corpus_hash=? AND sklearn_version=? "
                "AND method_version=? AND min_cluster_size=? AND min_samples=?", key).fetchone()
            cached = row is not None
            if cached:
                try:
                    labels = validate_labels(json.loads(row[0]), len(ids))
                except (ValueError, TypeError) as error:
                    raise ValueError("Invalid cached HDBSCAN result for size {} samples {}: {}".format(
                        size, sample_count, error)) from error
            else:
                if sample_count == 1:
                    estimator = SklearnHDBSCAN(
                        min_cluster_size=size, min_samples=1, metric="euclidean",
                        cluster_selection_method="eom", allow_single_cluster=False, n_jobs=1, copy=False)
                else:
                    estimator = HDBSCAN(
                        min_cluster_size=size, min_samples=sample_count - 1, metric="euclidean",
                        algorithm="generic", cluster_selection_method="eom", allow_single_cluster=False,
                        approx_min_span_tree=False, core_dist_n_jobs=1, memory=memory or Memory(location=None))
                labels = validate_labels(estimator.fit_predict(matrix).tolist(), len(ids))
                if cache is not None:
                    with cache:
                        cache.execute("INSERT OR REPLACE INTO cluster_results VALUES (?,?,?,?,?,?)",
                                      (*key, json.dumps(labels.tolist())))
            groups = {}
            for index, label in enumerate(labels):
                if label >= 0:
                    groups.setdefault(int(label), []).append(index)
            selected = [members for members in groups.values() if 3 <= len(members) <= 10]
            for members in selected:
                if cluster_settings is not None:
                    members_key = tuple(sorted(ids[index] for index in members))
                    cluster_settings.setdefault(members_key, []).append((size, sample_count))
                for source in members:
                    for target in members:
                        if source != target:
                            pair = (source, target)
                            votes[pair] = votes.get(pair, 0) + 1
            scans.append({"min_cluster_size": size, "min_samples": sample_count,
                          "clusters": len(groups), "candidate_clusters": len(selected),
                          "unassigned": int(np.count_nonzero(labels == -1))})
            print("Scan {}/{}: min_cluster_size={} min_samples={} candidate_clusters={} ({})".format(
                len(scans), total, size, sample_count, len(selected),
                "cached" if cached else "computed"), file=sys.stderr, flush=True)

    links = []
    agreement = {}
    candidates = {}
    for (source, target), count in votes.items():
        score = float(np.clip(normalized[source] @ normalized[target], -1, 1))
        candidates.setdefault(source, []).append((count, score, ids[target]))
    for source in sorted(candidates, key=lambda index: ids[index]):
        ranked = sorted(candidates[source], key=lambda item: (-item[0], -item[1], item[2]))
        for rank, (count, score, target) in enumerate(ranked[:limit], 1):
            links.append((ids[source], target, rank, score))
            agreement[(ids[source], target)] = count
    return links, agreement, scans


def compare_neighbors(ids, vectors, links, limit):
    """Compare agreement-ranked links with exact cosine neighbors, excluding self."""
    matrix = np.vstack(vectors)
    normalized = matrix / np.linalg.norm(matrix, axis=1)[:, None]
    neighbors_per_post = min(limit, len(ids) - 1)
    by_source = {}
    for source, target, rank, _score in links:
        by_source.setdefault(source, []).append((rank, target))
    comparisons = []
    for index, source in enumerate(ids):
        scores = np.clip(normalized @ normalized[index], -1, 1)
        candidates = sorted((i for i in range(len(ids)) if i != index),
                            key=lambda i: (-float(scores[i]), ids[i]))
        neighbors = candidates[:neighbors_per_post]
        neighbor_ids = [ids[i] for i in neighbors]
        clustered = [target for _rank, target in sorted(by_source.get(source, []))]
        common = set(clustered) & set(neighbor_ids)
        matched_common = set(clustered) & set(neighbor_ids[:len(clustered)])
        comparisons.append({
            "source_id": source, "hdbscan_targets": clustered,
            "neighbors": [{"target_id": ids[i], "similarity": float(scores[i])} for i in neighbors],
            "common_count": len(common),
            "top_k_overlap": len(common) / neighbors_per_post,
            "matched_length_overlap": len(matched_common) / len(clustered) if clustered else None,
            "same_first_target": clustered[0] == neighbor_ids[0] if clustered else None,
        })
    linked = [item for item in comparisons if item["hdbscan_targets"]]
    link_count = sum(len(item["hdbscan_targets"]) for item in comparisons)
    result = {
        "metric": "cosine_similarity", "exact_search": True,
        "neighbors_per_post": neighbors_per_post, "posts_compared": len(ids),
        "posts_with_hdbscan_links": len(linked), "hdbscan_coverage": len(linked) / len(ids),
        "mean_top_k_overlap_all_posts": sum(item["top_k_overlap"] for item in comparisons) / len(ids),
        "mean_matched_length_overlap_linked_posts": (
            sum(item["matched_length_overlap"] for item in linked) / len(linked) if linked else None),
        "hdbscan_links_in_neighbor_top_k_fraction": (
            sum(item["common_count"] for item in comparisons) / link_count if link_count else None),
        "same_first_target_fraction_linked_posts": (
            sum(item["same_first_target"] for item in linked) / len(linked) if linked else None),
        "per_post": comparisons,
    }
    score_counts = Counter(neighbor["similarity"] for item in comparisons for neighbor in item["neighbors"])
    retained = 0
    best_count = 0
    cutoff = None
    for score, count in sorted(score_counts.items(), reverse=True):
        retained += count
        if abs(retained - link_count) < abs(best_count - link_count):
            best_count = retained
            cutoff = score
    common_count = neighbor_posts = both_posts = same_first = 0
    for item in comparisons:
        filtered = [neighbor for neighbor in item["neighbors"]
                    if cutoff is not None and neighbor["similarity"] >= cutoff]
        item["filtered_neighbors"] = filtered
        clustered = set(item["hdbscan_targets"])
        shared = len(clustered & {neighbor["target_id"] for neighbor in filtered})
        item["filtered_common_count"] = shared
        common_count += shared
        neighbor_posts += bool(filtered)
        if clustered and filtered:
            both_posts += 1
            same_first += item["hdbscan_targets"][0] == filtered[0]["target_id"]
    result["distance_filtered"] = {
        "minimum_cosine_similarity": cutoff,
        "maximum_cosine_distance": 1 - cutoff if cutoff is not None else None,
        "target_links": link_count, "retained_links": best_count,
        "link_count_difference": best_count - link_count,
        "shared_links": common_count, "posts_with_links": neighbor_posts,
        "coverage": neighbor_posts / len(ids),
        "shared_fraction_of_hdbscan_links": common_count / link_count if link_count else None,
        "shared_fraction_of_filtered_links": common_count / best_count if best_count else None,
        "link_set_jaccard": common_count / (link_count + best_count - common_count)
        if link_count + best_count else None,
        "posts_with_links_in_both": both_posts,
        "same_first_target_fraction_both": same_first / both_posts if both_posts else None,
    }
    return result


def publish_links(connection, posts, links):
    connection.execute("PRAGMA foreign_keys=ON")
    try:
        connection.execute("BEGIN IMMEDIATE")
        if read_posts(connection) != posts:
            raise ValueError("Site posts changed during generation; rerun before publishing")
        hashes = {identifier: site_hash(title, body, fmt)
                  for identifier, title, body, fmt, _date in posts}
        connection.execute(RELATED_SCHEMA)
        connection.execute("DELETE FROM related_items")
        connection.executemany(
            "INSERT INTO related_items (source_id,target_id,rank,similarity,source_hash,target_hash) "
            "VALUES (?,?,?,?,?,?)",
            [(source, target, rank, score, hashes[source], hashes[target])
             for source, target, rank, score in links])
        connection.commit()
    except (sqlite3.Error, ValueError, KeyError):
        connection.rollback()
        raise


def run(database, cache_path, host, model, timeout, minimum, samples, limit, publish,
        include_snapshot=False, max_minimum=10, max_samples=10, include_clusters=False,
        include_link_titles=False, include_neighbor_comparison=False):
    if publish and include_neighbor_comparison:
        raise ValueError("Nearest-neighbor comparison is preview-only")
    database, cache_path = database.resolve(), cache_path.resolve()
    if database == cache_path or (cache_path.exists() and database.samefile(cache_path)):
        raise ValueError("Site database and embedding cache must be different files")
    if not database.is_file():
        raise ValueError("Site database does not exist: {}".format(database))
    parsed = urlparse(host)
    if parsed.scheme not in ("http", "https") or not parsed.netloc or parsed.query or parsed.fragment:
        raise ValueError("Ollama host must be an HTTP(S) base URL without query or fragment")
    if not model.strip() or not math.isfinite(timeout) or timeout <= 0:
        raise ValueError("Model must be nonempty and timeout must be positive and finite")
    if not 3 <= minimum <= max_minimum <= 10 or not 1 <= samples <= max_samples or limit < 1:
        raise ValueError("Require 3 <= min-cluster-size <= max-cluster-size <= 10, "
                         "1 <= min-samples <= max-samples, and a positive limit")
    site = sqlite3.connect(database.as_uri() + "?mode=rw", uri=True)
    try:
        if not publish:
            site.execute("PRAGMA query_only=ON")
        posts = read_posts(site)
        digest = model_digest(host, model, timeout)
        descriptor = os.open(cache_path, os.O_CREAT | os.O_WRONLY, 0o600)
        os.close(descriptor)
        cache = sqlite3.connect(cache_path)
        try:
            cache.execute(CACHE_SCHEMA)
            cache.execute(CLUSTER_CACHE_SCHEMA)
            ids, vectors, counts = embeddings_for_posts(posts, cache, host, model, digest, timeout)
            cluster_settings = {} if include_clusters else None
            with tempfile.TemporaryDirectory(prefix="dbnet-related-hierarchy-") as directory:
                memory = Memory(location=directory, verbose=0)
                links, agreement, scans = make_links(ids, vectors, minimum, max_minimum, samples, max_samples,
                                                    limit, cache, cluster_settings, memory)
        finally:
            cache.close()
        if model_digest(host, model, timeout) != digest:
            raise ValueError("Ollama model digest changed during generation; rerun")
        if publish:
            publish_links(site, posts, links)
        result = {"published": publish, "model": model, "model_digest": digest,
                "min_cluster_size_range": [minimum, max_minimum],
                "min_samples_range": [samples, max_samples], "candidate_cluster_size_range": [3, 10],
                "scan_count": len(scans), "scan_results": scans, "limit": limit,
                "posts": len(posts), "embedded": len(ids), **counts,
                "posts_without_links": len(ids) - len({link[0] for link in links}),
                "posts_with_links": len({link[0] for link in links}), "links": len(links),
                "proposals": [{"source_id": source, "target_id": target, "rank": rank, "similarity": score,
                               "agreement": agreement[(source, target)]}
                              for source, target, rank, score in links]}
        if include_snapshot:
            result["snapshot"] = [{"id": identifier, "hash": site_hash(title, body, fmt), "date": date}
                                  for identifier, title, body, fmt, date in posts]
        if include_link_titles or include_neighbor_comparison:
            result["link_titles"] = {identifier: title for identifier, title, _body, _fmt, _date in posts}
        if include_neighbor_comparison:
            result["neighbor_comparison"] = compare_neighbors(ids, vectors, links, limit)
        if cluster_settings is not None:
            titles = {identifier: title for identifier, title, _body, _fmt, _date in posts}
            result["candidate_clusters"] = [
                {"members": [{"id": identifier, "title": titles[identifier]} for identifier in members],
                 "settings": [{"min_cluster_size": size, "min_samples": samples}
                              for size, samples in settings]}
                for members, settings in sorted(cluster_settings.items(),
                                                key=lambda item: (-len(item[1]), item[0]))
            ]
        return result
    finally:
        site.close()


def format_cluster_report(result):
    clusters = result["candidate_clusters"]
    lines = ["Candidate clusters (3–10 posts): {}; proposed links: {}; published: {}".format(
        len(clusters), result["links"], "yes" if result["published"] else "no"),
        "Clusters may overlap; final links use pairwise agreement across settings."]
    for index, cluster in enumerate(clusters, 1):
        settings = ", ".join("{}/{}".format(item["min_cluster_size"], item["min_samples"])
                             for item in cluster["settings"])
        lines.append("\nCluster {} ({} posts, {} settings: min_cluster_size/min_samples {})".format(
            index, len(cluster["members"]), len(cluster["settings"]), settings))
        titles = [member["title"] for member in cluster["members"]]
        for member in cluster["members"]:
            title = json.dumps(member["title"], ensure_ascii=False)
            suffix = " [id: {}]".format(member["id"]) if titles.count(member["title"]) > 1 else ""
            lines.append("  - {}{}".format(title, suffix))
    return "\n".join(lines)


def format_link_report(result):
    titles = result["link_titles"]
    title_counts = Counter(titles.values())

    def display(identifier):
        title = titles[identifier]
        label = json.dumps(title, ensure_ascii=False)
        if title_counts[title] > 1:
            label += " [id: {}]".format(identifier)
        return label

    by_source = {}
    for proposal in result["proposals"]:
        by_source.setdefault(proposal["source_id"], []).append(proposal)
    lines = ["Final related links: {} across {} posts ({})".format(
        result["links"], len(by_source), "published locally" if result["published"] else "proposed")]
    for source in sorted(by_source, key=lambda identifier: (titles[identifier].casefold(), identifier)):
        lines.append("\n" + display(source))
        for proposal in sorted(by_source[source], key=lambda item: item["rank"]):
            lines.append("  {}. {} (agreement: {}, similarity: {:.3f})".format(
                proposal["rank"], display(proposal["target_id"]),
                proposal["agreement"], proposal["similarity"]))
    if not by_source:
        lines.append("No related links.")
    return "\n".join(lines)


def format_neighbor_comparison(result):
    comparison = result["neighbor_comparison"]
    filtered = comparison["distance_filtered"]
    titles = result["link_titles"]
    k = comparison["neighbors_per_post"]

    def percentage(value):
        return "n/a" if value is None else "{:.1%}".format(value)

    def display(identifier):
        return "{} [id: {}]".format(json.dumps(titles[identifier], ensure_ascii=False), identifier)

    differing = [item for item in comparison["per_post"]
                 if item["hdbscan_targets"] != [neighbor["target_id"] for neighbor in item["filtered_neighbors"]]]
    lines = [
        "HDBSCAN vs distance-filtered cosine neighbors (preview only)",
        "Model: {}; digest: {}".format(result["model"], result["model_digest"]),
        "Scan: min_cluster_size {}; min_samples {}; eligible cluster sizes {}".format(
            result["min_cluster_size_range"], result["min_samples_range"],
            result["candidate_cluster_size_range"]),
        "Compared {} embedded posts; up to {} neighbors per post; self excluded.".format(
            comparison["posts_compared"], k),
        "HDBSCAN coverage: {} ({} posts)".format(
            percentage(comparison["hdbscan_coverage"]), comparison["posts_with_hdbscan_links"]),
        "Overlap measures agreement, not recommendation quality.",
        "HDBSCAN retains its existing Euclidean fits; only the neighbor baseline uses cosine.",
        "",
        "Distance-filtered cosine baseline (global cutoff calibrated to total HDBSCAN links):",
        "Keep cosine similarity >= {}; equivalently cosine distance <= {}.".format(
            repr(filtered["minimum_cosine_similarity"]), repr(filtered["maximum_cosine_distance"]))
        if filtered["minimum_cosine_similarity"] is not None else "Keep no cosine links (empty selection).",
        "Retained links: {}; HDBSCAN target: {}; difference: {:+d}.".format(
            filtered["retained_links"], filtered["target_links"], filtered["link_count_difference"]),
        "Equal-score ties stay together; closest achievable count wins; equally close counts prefer fewer links.",
        "Filtered cosine coverage: {} ({} posts); HDBSCAN coverage: {}.".format(
            percentage(filtered["coverage"]), filtered["posts_with_links"],
            percentage(comparison["hdbscan_coverage"])),
        "Shared directed links: {}; fraction of HDBSCAN links: {}; fraction of filtered cosine links: {}.".format(
            filtered["shared_links"], percentage(filtered["shared_fraction_of_hdbscan_links"]),
            percentage(filtered["shared_fraction_of_filtered_links"])),
        "Directed-link set Jaccard: {}.".format(percentage(filtered["link_set_jaccard"])),
        "Same first recommendation among {} posts with links in both: {}.".format(
            filtered["posts_with_links_in_both"], percentage(filtered["same_first_target_fraction_both"])),
        "The cutoff matches recommendation volume on this corpus, not relevance or a transferable quality threshold.",
        "Summary metrics cover all posts. Showing {} posts with differing membership or ranking; {} identical lists omitted.".format(
            len(differing), comparison["posts_compared"] - len(differing)),
    ]
    for item in sorted(differing,
                       key=lambda item: (titles[item["source_id"]].casefold(), item["source_id"])):
        clustered = item["hdbscan_targets"]
        common = set(clustered) & {neighbor["target_id"] for neighbor in item["filtered_neighbors"]}
        lines.append("\n{}: {} shared; HDBSCAN {}; filtered cosine {}".format(
            display(item["source_id"]), len(common), len(clustered), len(item["filtered_neighbors"])))
        lines.append("  HDBSCAN (agreement-first order; * = in both lists):")
        for rank, target in enumerate(clustered, 1):
            lines.append("    {}. {}{}".format(rank, display(target), " *" if target in common else ""))
        if not clustered:
            lines.append("    No eligible cluster links.")
        lines.append("  Distance-filtered cosine neighbors ({} shared with HDBSCAN):".format(
            item["filtered_common_count"]))
        for rank, neighbor in enumerate(item["filtered_neighbors"], 1):
            target = neighbor["target_id"]
            lines.append("    {}. {} (cosine {:.3f}){}".format(
                rank, display(target), neighbor["similarity"], " *" if target in clustered else ""))
        if not item["filtered_neighbors"]:
            lines.append("    No neighbors pass the global cutoff.")
    return "\n".join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", type=Path, default=DEFAULT_DATABASE)
    parser.add_argument("--cache", type=Path, default=DEFAULT_CACHE)
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--timeout", type=float, default=120)
    parser.add_argument("--min-cluster-size", type=int, default=3)
    parser.add_argument("--max-cluster-size", type=int, default=10,
                        help="Largest min_cluster_size setting to scan (at most 10)")
    parser.add_argument("--min-samples", type=int, default=2)
    parser.add_argument("--max-samples", type=int, default=10)
    parser.add_argument("--limit", type=int, default=10)
    parser.add_argument("--publish", action="store_true", help="Replace related links in the selected database")
    parser.add_argument("--include-snapshot", action="store_true",
                        help="Include post IDs, hashes and dates in the JSON output")
    report = parser.add_mutually_exclusive_group()
    report.add_argument("--clusters", action="store_true",
                        help="Print a human-readable candidate-cluster report instead of JSON")
    report.add_argument("--show-clusters", action="store_true",
                        help="Print the candidate-cluster report to stderr, retaining JSON on stdout")
    report.add_argument("--links", action="store_true",
                        help="Print final related links by title instead of JSON")
    report.add_argument("--show-links", action="store_true",
                        help="Print final links by title to stderr, retaining JSON on stdout")
    report.add_argument("--compare-neighbors", action="store_true",
                        help="Preview HDBSCAN vs exact cosine neighbors using the same embeddings; no publishing")
    parser.add_argument("--link-report", type=Path,
                        help="Write final links by title to a separate file while retaining JSON on stdout")
    args = parser.parse_args()
    if args.compare_neighbors and (args.publish or args.include_snapshot or args.link_report):
        parser.error("--compare-neighbors is a standalone preview; cannot publish, include a snapshot, or write a link report")
    if (args.clusters or args.links) and args.include_snapshot:
        parser.error("--clusters and --links cannot be combined with --include-snapshot; "
                     "use --show-clusters or --show-links")
    if args.link_report and (args.link_report.resolve() in (args.database.resolve(), args.cache.resolve())
                             or any(args.link_report.exists() and path.exists()
                                    and args.link_report.samefile(path) for path in (args.database, args.cache))):
        parser.error("--link-report must not overwrite the site database or embedding cache")
    try:
        result = run(args.database, args.cache, args.host, args.model, args.timeout,
                     args.min_cluster_size, args.min_samples, args.limit, args.publish,
                     args.include_snapshot, args.max_cluster_size, args.max_samples,
                     args.clusters or args.show_clusters, args.links or args.show_links or bool(args.link_report),
                     args.compare_neighbors)
        if args.compare_neighbors:
            print(format_neighbor_comparison(result))
        if args.clusters or args.show_clusters:
            print(format_cluster_report(result), file=sys.stderr if args.show_clusters else sys.stdout)
            del result["candidate_clusters"]
        if args.links or args.show_links:
            print(format_link_report(result), file=sys.stderr if args.show_links else sys.stdout)
        if args.link_report:
            args.link_report.write_text(format_link_report(result) + "\n", encoding="utf-8")
        if args.links or args.show_links or args.link_report:
            del result["link_titles"]
        if not (args.clusters or args.links or args.compare_neighbors):
            print(json.dumps(result, indent=2, allow_nan=False))
    except (OSError, sqlite3.Error, ValueError, RuntimeError) as error:
        print("Error: {}".format(error), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("Interrupted; cached embeddings are preserved for a rerun.", file=sys.stderr)
        return 130
    return 0


if __name__ == "__main__":
    sys.exit(main())
