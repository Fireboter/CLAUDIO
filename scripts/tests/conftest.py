# scripts/tests/conftest.py
import importlib.util
import sys
from pathlib import Path


def _load_run_tests():
    scripts_dir = Path(__file__).parent.parent  # D:\CLAUDIO\scripts
    spec = importlib.util.spec_from_file_location(
        'run_tests', scripts_dir / 'run-tests.py'
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules['run_tests'] = mod
    spec.loader.exec_module(mod)
    return mod


_load_run_tests()
