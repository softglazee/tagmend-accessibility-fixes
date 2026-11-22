// Records a short silent WebM (about 4 seconds, 320x180) with the workspace's
// headless Chromium, for the autoplay <video> on the a11y-fx-structure fixture.
//
// Usage: node tools/fixtures/make-video.mjs <output.webm>
import { mkdirSync, renameSync, rmSync } from 'node:fs';
import path from 'node:path';
import { useLocalBrowsers } from '../a11y/lib.mjs';

const out = process.argv[2];
if (!out) {
  console.error('Usage: node tools/fixtures/make-video.mjs <output.webm>');
  process.exit(2);
}
const tmpDir = path.join(path.dirname(path.resolve(out)), '.video-tmp');
mkdirSync(tmpDir, { recursive: true });

useLocalBrowsers();
const { chromium } = await import('playwright');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 320, height: 180 },
  recordVideo: { dir: tmpDir, size: { width: 320, height: 180 } },
});
const page = await context.newPage();
await page.setContent(`<!doctype html><html lang="en"><body style="margin:0;background:#7a4a1e;color:#fff8e7;font:24px sans-serif;display:flex;align-items:center;justify-content:center;height:180px">
<p id="t">Example Bakery oven</p>
<script>let n=0;setInterval(()=>{document.body.style.background=['#7a4a1e','#a8642a','#c98a3c'][n++%3];},500);</script>
</body></html>`);
await page.waitForTimeout(4000);
const video = page.video();
await context.close();
const recorded = await video.path();
await browser.close();
renameSync(recorded, out);
rmSync(tmpDir, { recursive: true, force: true });
console.log(`wrote ${out}`);
