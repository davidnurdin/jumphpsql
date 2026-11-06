"""Builds site/images/ from var/site-shots/*.png (made by SiteShotsTest) and assets/icon.png. Run by shots.sh."""
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

app = Path(sys.argv[1])
shots, out = app / 'var/site-shots', app / 'site/images'
out.mkdir(parents=True, exist_ok=True)
for name in ('data', 'structure', 'sql', 'dark'):
    im = Image.open(shots / f'{name}.png').convert('RGB')
    im.resize((1400, round(im.height * 1400 / im.width)), Image.LANCZOS).save(out / f'{name}.webp', quality=86, method=6)
icon = Image.open(app / 'assets/icon.png').convert('RGBA')
icon.resize((256, 256), Image.LANCZOS).save(out / 'icon.png')
icon.resize((64, 64), Image.LANCZOS).save(out / 'favicon.png')
# og.png 1200 x 630: icon, name, a slice of the data screenshot
W, H = 1200, 630
og = Image.new('RGBA', (W, H))
d = ImageDraw.Draw(og)
for y in range(H):
    t = y / (H - 1)
    d.line([(0, y), (W, y)], fill=(int(34 - 14 * t), int(30 - 12 * t), int(92 - 36 * t), 255))
shot = Image.open(shots / 'sql.png').convert('RGBA')
shot = shot.resize((760, round(shot.height * 760 / shot.width)), Image.LANCZOS)
mask = Image.new('L', shot.size, 0)
ImageDraw.Draw(mask).rounded_rectangle([0, 0, shot.width - 1, shot.height - 1], radius=18, fill=255)
og.paste(shot, (520, 150), mask)
og.alpha_composite(icon.resize((150, 150), Image.LANCZOS), (60, 60))
fonts = app / 'assets/fonts'
fb, fr = ImageFont.truetype(str(fonts / 'ui-bold.ttf'), 64), ImageFont.truetype(str(fonts / 'ui.ttf'), 30)
d = ImageDraw.Draw(og)
d.text((60, 250), 'jumphpsql', font=fb, fill=(255, 255, 255))
for i, line in enumerate(('MySQL / MariaDB client', 'written in PHP', 'Browse · Edit · Query · Dump')):
    d.text((60, 345 + i * 46), line, font=fr, fill=(255, 166, 77) if i == 0 else (205, 208, 245))
og.convert('RGB').save(out / 'og.png')
