# Image sources

| Source | Generates | Used in |
|---|---|---|
| `system-design.html` | `../images/system-design.png` | README ("Under the hood") and the docs |
| `logo-lockup.html` | `../images/logo-lockup-light.png`, `../images/logo-lockup-dark.png` | README header |
| `make-transparent-logo.php` | `../images/logo-transparent.png` (from `logo.png`) | the lockups |

The diagram is plain HTML with an inline SVG, so boxes and arrows are positioned by
hand: edit the coordinates, then regenerate the PNG.

## Logo lockup (logo + "kmzinho")

`logo.png` has an opaque white background, which shows as a white square on GitHub's
dark theme. So the README uses a lockup with a transparent background, in two
versions, and a `<picture>` element picks the one matching the reader's theme.

```powershell
php docs\design\make-transparent-logo.php      # only when logo.png changes

$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$html = "file:///" + (Resolve-Path docs\design\logo-lockup.html).Path.Replace('\','/')
foreach ($v in @(@{n="light"; h=""}, @{n="dark"; h="#dark"})) {
  & $edge --headless=new --disable-gpu --hide-scrollbars --default-background-color=00000000 `
    --force-device-scale-factor=2 --window-size=760,220 --virtual-time-budget=8000 `
    --screenshot="$PWD\docs\images\logo-lockup-$($v.n).png" "$html$($v.h)"
}
```

## Regenerate the diagram

Any Chromium browser in headless mode works. It needs internet access to load the
fonts (Inter and JetBrains Mono, from Google Fonts).

Windows (Edge), from the repository root:

```powershell
$edge = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$html = (Resolve-Path docs\design\system-design.html).Path
& $edge --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 `
  --window-size=1600,1000 --virtual-time-budget=8000 `
  --screenshot="$PWD\docs\images\system-design.png" "file:///$($html.Replace('\','/'))"
```

Linux/macOS (Chrome):

```bash
google-chrome --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
  --window-size=1600,1000 --virtual-time-budget=8000 \
  --screenshot="$PWD/docs/images/system-design.png" "file://$PWD/docs/design/system-design.html"
```

The canvas is 1600x1000 CSS pixels, rendered at 2x (3200x2000) so it stays sharp.
