#!/usr/bin/env python3
"""Compile plugins/*.wat to plugins/*.wasm using the `wasmtime` PyPI package.

Optional helper: the build and `cargo test` do NOT depend on it — cargo
regenerates and verifies all example plugins automatically through the
dev-dependency `wat` (see src/main.rs, `tests::example_wat_plugins_compile`).

    pip install wasmtime && python compile_plugins.py
"""
import pathlib
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

try:
    from wasmtime import wat2wasm
except ImportError:
    print("wasmtime not installed: pip install wasmtime")
    sys.exit(1)

root = pathlib.Path(__file__).resolve().parent
for wat_file in sorted(root.glob("*.wat")):
    wasm_file = wat_file.with_suffix(".wasm")
    wasm_file.write_bytes(wat2wasm(wat_file.read_text(encoding="utf-8")))
    print(f"   {wat_file.name} -> {wasm_file.name} ({wasm_file.stat().st_size} bytes)")
print("done")
