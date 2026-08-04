# Standee Fonts

The print-ready standee uses TrueType fonts for big, crisp typography
of the Business Name and "Scan here for 5-Star Review" headings.

Template-based standees (`generateStandeeFromTemplate`) write **no text
at all** onto the image — the template uploaded by the admin is treated
as a finished design, and only a plain QR is pasted into the fixed
"design zone" (see the constants on `QrService` for the exact pixel
coordinates). No fonts are required for that path.

## Auto-detection order

`app/services/QrService.php` looks (in order) for:

1. `public/lib/fonts/DejaVuSans-Bold.ttf` (this folder)
2. `public/lib/fonts/Roboto-Bold.ttf`
3. Common Linux system paths (DejaVu / Liberation under
   `/usr/share/fonts/...`)
4. Common Windows system paths (`C:\Windows\Fonts\arialbd.ttf` etc.)

If none are found the code falls back to a scaled-up GD bitmap so
the standee always renders.

## To force a specific look

Drop a `.ttf` file in this folder (recommended:
`DejaVuSans-Bold.ttf` from https://dejavu-fonts.github.io/) so the
output is identical across every server.

The file path on disk is read directly with `imagettftext()`, so no
DB / config change is needed.
