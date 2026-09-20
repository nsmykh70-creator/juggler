//! Build script.
//!
//! Compiles `proto/control_plane.proto` (package `juggler.control`) into Rust
//! types for tonic.
//!
//! tonic-build normally shells out to a system `protoc` binary. This workspace
//! has none, so a protoc binary (protobuf v32.1 release from
//! github.com/protocolbuffers/protobuf) is vendored under `third_party/protoc/`
//! and exported to tonic-build via $PROTOC. `cargo build` therefore works from
//! a clean checkout with zero external dependencies.

use std::path::PathBuf;

fn main() {
    // Locate a usable protoc: vendored binary takes priority, then $PROTOC.
    let protoc = find_protoc();
    println!("cargo:rerun-if-changed={}", protoc.display());

    std::env::set_var("PROTOC", &protoc);

    tonic_build::configure()
        .build_server(true)
        .build_client(true)
        .out_dir(PathBuf::from(std::env::var("OUT_DIR").unwrap()))
        .compile_protos(&["proto/control_plane.proto"], &["proto"])
        .expect("failed to compile gRPC protocol (control_plane.proto)");
}

fn find_protoc() -> PathBuf {
    // 1. Vendored binary committed with the repo, matching the host OS.
    //    The repo ships the win64 build; Linux/macOS checkouts (CI, Docker)
    //    fall through to the system protoc below.
    let vendored_name = if cfg!(windows) { "protoc.exe" } else { "protoc" };
    let vendored = PathBuf::from("third_party/protoc/bin").join(vendored_name);
    if vendored.exists() {
        return std::fs::canonicalize(vendored).expect("canonicalize vendored protoc");
    }

    // 2. Environment override.
    if let Ok(p) = std::env::var("PROTOC") {
        let p = PathBuf::from(p);
        if p.exists() {
            return p;
        }
    }

    // 3. System protoc in PATH (Linux/macOS: `apt install protobuf-compiler`,
    //    `brew install protobuf`, or a preinstalled GitHub Actions runner).
    if let Ok(out) = std::process::Command::new("protoc").arg("--version").output() {
        if out.status.success() {
            return PathBuf::from("protoc");
        }
    }

    panic!(
        "protoc not found: expected {} (vendored), a valid $PROTOC, or `protoc` in PATH. \
         Install protobuf-compiler or vendor the binary.",
        vendored.display()
    );
}
