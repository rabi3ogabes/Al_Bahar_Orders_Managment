import pathlib, sys
sys.path.insert(0, str(pathlib.Path(__file__).parent))
from art import svg
from scenes import SCENES
out = pathlib.Path('/tmp/skyline_check'); out.mkdir(exist_ok=True)
rows = []
for key, fn in SCENES.items():
    s = fn()
    (out / f'{key}.svg').write_text(svg(s))
    print(key, len(s.shapes), 'shapes', sum(len(p) for p in s.shapes), 'points')
