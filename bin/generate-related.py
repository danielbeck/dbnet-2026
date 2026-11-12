#!/usr/bin/env python3
"""Generate or publish related pool items using a local Ollama server."""

import argparse
from bisect import bisect_right
from datetime import datetime, timezone
import hashlib
import json
import math
import os
import re
import sqlite3
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

import numpy as np
from sklearn.cluster import HDBSCAN


ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DATABASE = ROOT / "storage" / "dbnet.sqlite"
DEFAULT_CACHE = ROOT / "storage" / "related-embeddings.sqlite"
DEFAULT_HOST = "http://192.168.0.98:11434"
DEFAULT_MODEL = "qwen3-embedding:0.6b"
METHOD_VERSION = "site-document-v1-ctx32768-chunks2000-overlap200"
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


def make_links(ids, vectors, minimum, samples, limit):
    if len(ids) < max(minimum, samples):
        raise ValueError("Not enough substantive posts for the selected HDBSCAN parameters")
    matrix = np.vstack(vectors)
    estimator = HDBSCAN(min_cluster_size=minimum, min_samples=samples, metric="euclidean",
                        cluster_selection_method="eom", allow_single_cluster=False, n_jobs=1)
    labels = estimator.fit_predict(matrix)
    normalized = matrix / np.linalg.norm(matrix, axis=1)[:, None]
    links = []
    groups = {}
    for index, label in enumerate(labels):
        if label >= 0:
            groups.setdefault(int(label), []).append(index)
    for members in groups.values():
        for source in members:
            ranked = sorted(
                ((float(np.clip(normalized[source] @ normalized[target], -1, 1)), ids[target])
                 for target in members if target != source),
                key=lambda pair: (-pair[0], pair[1]),
            )
            links.extend((ids[source], target, rank, score)
                         for rank, (score, target) in enumerate(ranked[:limit], 1))
    return links, len(groups), int(np.count_nonzero(labels == -1))


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
        include_snapshot=False):
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
    if minimum < 2 or samples < 1 or limit < 1:
        raise ValueError("Minimum cluster size must be at least 2; samples and limit must be positive")
    site = sqlite3.connect(database.as_uri() + ("?mode=rw" if publish else "?mode=ro"), uri=True)
    try:
        posts = read_posts(site)
        digest = model_digest(host, model, timeout)
        descriptor = os.open(cache_path, os.O_CREAT | os.O_WRONLY, 0o600)
        os.close(descriptor)
        cache = sqlite3.connect(cache_path)
        try:
            cache.execute(CACHE_SCHEMA)
            ids, vectors, counts = embeddings_for_posts(posts, cache, host, model, digest, timeout)
        finally:
            cache.close()
        links, clusters, unassigned = make_links(ids, vectors, minimum, samples, limit)
        if model_digest(host, model, timeout) != digest:
            raise ValueError("Ollama model digest changed during generation; rerun")
        if publish:
            publish_links(site, posts, links)
        result = {"published": publish, "model": model, "model_digest": digest,
                "min_cluster_size": minimum, "min_samples": samples, "limit": limit,
                "posts": len(posts), "embedded": len(ids), **counts,
                "clusters": clusters, "unassigned": unassigned,
                "posts_with_links": len({link[0] for link in links}), "links": len(links),
                "proposals": [{"source_id": source, "target_id": target, "rank": rank, "similarity": score}
                              for source, target, rank, score in links]}
        if include_snapshot:
            result["snapshot"] = [{"id": identifier, "hash": site_hash(title, body, fmt), "date": date}
                                  for identifier, title, body, fmt, date in posts]
        return result
    finally:
        site.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", type=Path, default=DEFAULT_DATABASE)
    parser.add_argument("--cache", type=Path, default=DEFAULT_CACHE)
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--timeout", type=float, default=120)
    parser.add_argument("--min-cluster-size", type=int, default=3)
    parser.add_argument("--min-samples", type=int, default=2)
    parser.add_argument("--limit", type=int, default=10)
    parser.add_argument("--publish", action="store_true", help="Replace related links in the selected database")
    parser.add_argument("--include-snapshot", action="store_true",
                        help="Include post IDs, hashes and dates in the JSON output")
    args = parser.parse_args()
    try:
        result = run(args.database, args.cache, args.host, args.model, args.timeout,
                     args.min_cluster_size, args.min_samples, args.limit, args.publish,
                     args.include_snapshot)
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
