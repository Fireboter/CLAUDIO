"""
check_shop_images.py — Use Playwright to crawl all /shop pages and find products missing images.
Usage: python check_shop_images.py
"""
import sys
from playwright.sync_api import sync_playwright

BASE_URL = 'https://kokett.ad'

def check_all_pages():
    missing = []   # list of (page_num, product_name, product_url)
    ok_count = 0

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        # First: find total pages
        page.goto(f'{BASE_URL}/shop', wait_until='domcontentloaded', timeout=30000)
        # Count total pages from pagination or just iterate until empty
        page_num = 1

        while True:
            url = f'{BASE_URL}/shop?page={page_num}'
            print(f'Checking page {page_num}: {url}')
            resp = page.goto(url, wait_until='domcontentloaded', timeout=30000)

            # Get all product cards
            cards = page.query_selector_all('.product-card')
            if not cards:
                print(f'  No products on page {page_num} — done.')
                break

            for card in cards:
                # Get product name
                name_el = card.query_selector('h3')
                name = name_el.inner_text().strip() if name_el else '(unknown)'

                # Get product link
                href = card.get_attribute('href') or ''
                product_url = BASE_URL + href if href.startswith('/') else href

                # Check if there's an <img> with a valid src
                img_el = card.query_selector('img')
                has_image = False
                if img_el:
                    src = img_el.get_attribute('src') or ''
                    # Valid if it's a real image path (not empty, not placeholder)
                    if src and src != '' and not src.endswith('undefined'):
                        has_image = True

                if has_image:
                    ok_count += 1
                else:
                    missing.append((page_num, name, product_url))
                    print(f'  MISSING IMAGE: "{name}" → {product_url}')

            print(f'  Page {page_num}: {len(cards)} products, {sum(1 for m in missing if m[0] == page_num)} missing')
            page_num += 1

        browser.close()

    print(f'\n--- Summary ---')
    print(f'Products with image:    {ok_count}')
    print(f'Products missing image: {len(missing)}')
    if missing:
        print('\nMissing image products:')
        for pg, name, url in missing:
            print(f'  [page {pg}] {name}')
            print(f'            {url}')

    return missing

if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    missing = check_all_pages()
    sys.exit(1 if missing else 0)
