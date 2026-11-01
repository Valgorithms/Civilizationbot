"""Register zero-process round-trip contracts in the existing offline CI entrypoint."""
from pathlib import Path

def load_tests(loader, tests, pattern):
    root = Path(__file__).resolve().parents[1] / 'roundtrip'
    return loader.discover(str(root), pattern='test_*.py', top_level_dir=str(root))
