"""Offline Chromium layout smoke against real PHP render + embedded assets.
No network or database needed: PHP runs with the uninstalled guest state.
"""
import asyncio
import base64
import re
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parent.parent
OUT = Path('/mnt/data/azh_homepage_work')


def offline_render():
    html = subprocess.check_output(['php', str(ROOT/'index.php')], cwd=ROOT).decode('utf-8')
    assert '<main id="main-content">' in html
    css = '\n'.join((ROOT/f'assets/{s}').read_text() for s in ['form-light.css', 'universal-header.css', 'landing.css'])
    css = re.sub(r'@import[^;]+;', '', css)
    html = re.sub(r'<link[^>]+rel="stylesheet"[^>]*>', '', html)
    html = re.sub(r'<link[^>]+rel="preload"[^>]*>', '', html)
    html = re.sub(r'<script[^>]+src="[^"]+"[^>]*></script>', '', html)
    html = html.replace('</head>', f'<style>{css}</style></head>')
    for filename in ['hero-reality-white.webp', 'hero-reality-white-mobile.webp']:
        uri = 'data:image/webp;base64,' + base64.b64encode((ROOT/'assets'/filename).read_bytes()).decode('ascii')
        html = html.replace('assets/' + filename, uri)
    return html


async def main():
    html = offline_render()
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox'])
        for name, width, height in [('desktop',1440,900),('tablet',820,1000),('mobile',390,844),('small',320,690)]:
            page = await browser.new_page(viewport={'width':width,'height':height}, device_scale_factor=1)
            errors=[]
            page.on('pageerror',lambda e:errors.append(str(e)))
            await page.set_content(html, wait_until='load')
            facts=await page.evaluate('''() => {
             const img = document.querySelector('.azh-home-hero-media img');
             const hero = document.querySelector('.azh-home-hero-media').getBoundingClientRect();
             const h1 = document.querySelector('h1').getBoundingClientRect();
             const masthead = document.querySelector('.azh-home-masthead').getBoundingClientRect();
             return {documentWidth:document.documentElement.scrollWidth, windowWidth:window.innerWidth,
               imageLoaded:img.complete&&img.naturalWidth>0, mobileImage:img.currentSrc.includes('base64')&&window.matchMedia('(max-width: 640px)').matches,
               heroHeight:Math.round(hero.height), headingBottom:Math.round(h1.bottom), mastheadBottom:Math.round(masthead.bottom),
               h1Count:document.querySelectorAll('h1').length,
               links:[...document.querySelectorAll('.azh-home-button')].map(x=>x.getAttribute('href')),
               background:getComputedStyle(document.body).backgroundColor }
            }''')
            assert facts['imageLoaded'],facts
            assert facts['documentWidth'] <= facts['windowWidth']+1,(name,'horizontal overflow',facts)
            assert facts['h1Count']==1
            assert not errors,errors
            assert facts['heroHeight']>180
            await page.screenshot(path=str(OUT / f'AZHottie_v1291_home_{name}.png'),full_page=True)
            print(name,facts)
            await page.close()
        await browser.close()

if __name__=='__main__':
    asyncio.run(main())
