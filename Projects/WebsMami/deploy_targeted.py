"""
Targeted FTP deploy: upload specific files to Kokett and Bawywear servers.
Uses passive mode FTP. Credentials sourced from existing deploy scripts.
"""

from ftplib import FTP, error_perm
from pathlib import Path

UPLOADS = [
    # --- Kokett ---
    {
        "label":  "kokett/pages/shop.php",
        "local":  r"C:\WebsMami\kokett\pages\shop.php",
        "remote": "/pages/shop.php",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/pages/layout-header.php",
        "local":  r"C:\WebsMami\kokett\pages\layout-header.php",
        "remote": "/pages/layout-header.php",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/public/assets/css/main.css",
        "local":  r"C:\WebsMami\kokett\public\assets\css\main.css",
        "remote": "/public/assets/css/main.css",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/public/assets/css/admin.css",
        "local":  r"C:\WebsMami\kokett\public\assets\css\admin.css",
        "remote": "/public/assets/css/admin.css",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/admin/pages/layout-header.php",
        "local":  r"C:\WebsMami\kokett\admin\pages\layout-header.php",
        "remote": "/admin/pages/layout-header.php",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/admin/pages/marketing.php",
        "local":  r"C:\WebsMami\kokett\admin\pages\marketing.php",
        "remote": "/admin/pages/marketing.php",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "kokett/admin/pages/product-form.php",
        "local":  r"C:\WebsMami\kokett\admin\pages\product-form.php",
        "remote": "/admin/pages/product-form.php",
        "host":   "ftp.kokett.ad",
        "user":   "claude.kokett.ad",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    # --- Bawywear ---
    {
        "label":  "bawywear/pages/shop.php",
        "local":  r"C:\WebsMami\bawywear\pages\shop.php",
        "remote": "/pages/shop.php",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/pages/layout-header.php",
        "local":  r"C:\WebsMami\bawywear\pages\layout-header.php",
        "remote": "/pages/layout-header.php",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/public/assets/css/main.css",
        "local":  r"C:\WebsMami\bawywear\public\assets\css\main.css",
        "remote": "/public/assets/css/main.css",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/public/assets/css/admin.css",
        "local":  r"C:\WebsMami\bawywear\public\assets\css\admin.css",
        "remote": "/public/assets/css/admin.css",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/admin/pages/layout-header.php",
        "local":  r"C:\WebsMami\bawywear\admin\pages\layout-header.php",
        "remote": "/admin/pages/layout-header.php",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/admin/pages/marketing.php",
        "local":  r"C:\WebsMami\bawywear\admin\pages\marketing.php",
        "remote": "/admin/pages/marketing.php",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
    {
        "label":  "bawywear/admin/pages/product-form.php",
        "local":  r"C:\WebsMami\bawywear\admin\pages\product-form.php",
        "remote": "/admin/pages/product-form.php",
        "host":   "ftp.bawywear.com",
        "user":   "ftp.bawywear.com",
        "passwd": "KarenVB_13061975",
        "port":   21,
    },
]


def ensure_remote_dir(ftp: FTP, remote_path: str) -> None:
    """Create parent directories on the FTP server if they don't exist."""
    parts = [p for p in remote_path.split("/") if p]
    dirs = parts[:-1]  # everything except the filename
    current = ""
    for d in dirs:
        current += "/" + d
        try:
            ftp.mkd(current)
        except error_perm:
            pass  # already exists


def upload_file(task: dict) -> bool:
    local = Path(task["local"])
    remote = task["remote"]
    label = task["label"]

    if not local.exists():
        print(f"  [FAIL] {label} — local file not found: {local}")
        return False

    ftp = FTP()
    try:
        ftp.connect(task["host"], task["port"], timeout=30)
        ftp.login(task["user"], task["passwd"])
        ftp.set_pasv(True)  # passive mode

        ensure_remote_dir(ftp, remote)

        with open(local, "rb") as f:
            ftp.storbinary(f"STOR {remote}", f)

        print(f"  [OK]   {label}  ->  {task['host']}:{remote}")
        return True
    except Exception as e:
        print(f"  [FAIL] {label}  ->  {e}")
        return False
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()


def main():
    print("=== Targeted FTP Deploy ===\n")
    ok = 0
    fail = 0
    for task in UPLOADS:
        print(f"Uploading {task['label']} ...")
        if upload_file(task):
            ok += 1
        else:
            fail += 1

    print(f"\nFinished: {ok} succeeded, {fail} failed.")


if __name__ == "__main__":
    main()
