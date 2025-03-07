import puppeteer, {
    type Browser,
    type ConsoleMessage,
    type HTTPRequest,
} from "puppeteer";
import fs from "fs-extra";
import path from "node:path";
import { gray, cyan, magenta, red, yellow } from "colorette";

const main = async () => {
    await runPuppeteerScript();
};

main().catch((err) => console.error("Error during operation:", err));

// ===================================== END MAIN ===========================================

async function runPuppeteerScript(): Promise<void> {
    clearDirectoryExcept(
        path.resolve(import.meta.dirname, "../../_data"),
        path.resolve(import.meta.dirname, "../../_data/i"),
    ).catch((err) => console.error("Error during operation:", err));

    const browser: Browser = await puppeteer.launch({
        args: ['--no-sandbox'],
        headless: true,
        defaultViewport: { width: 1280, height: 1024 },
        devtools: true,
    });

    const browserContext = browser.defaultBrowserContext();

    await browserContext.setCookie({
        name: "XDEBUG_SESSION",
        value: "VSCODE",
        domain: "localhost",
    });

    const pages = await browser.pages();
    const page = pages[0];

    page.setDefaultTimeout(20_000);

    page.on("console", handleConsoleMessage)
        .on("pageerror", ({ message }) => console.log(red(message)))
        .on("requestfailed", (request) => console.log(magenta(`${request.failure()?.errorText} ${request.url()}`)));

    await page.setRequestInterception(true);
    page.on("request", handleRequest);

    await fs.remove(
        path.resolve(import.meta.dirname, "../../local/config/database.php"),
    );

    // Navigate to the install page
    await page.goto("http://localhost/piwigo-fork/install.php", { waitUntil: "networkidle0" });

    await page.waitForSelector("#content > form > fieldset:nth-child(2) > table > tbody > tr:nth-child(2) > td:nth-child(2) > input[type=text]");

    // install button
    await page.click("#content > form > div > input");

    // Admin pages

    // go to homepage
    await page.waitForSelector("#content > p > a");
    await page.click("#content > p > a");
    // deactivate empty gallery message
    await page.waitForSelector("#deactivate > a");
    await page.click("#deactivate > a");
    // admin button
    await page.waitForSelector("#menubar > dl:nth-child(7) > dt > a:nth-child(4)");
    await page.click("#menubar > dl:nth-child(7) > dt > a:nth-child(4)");
    // hide subscribe to newsletter button
    await page.waitForSelector("#content > p > span > a.newsletter-hide");
    await page.click("#content > p > span > a.newsletter-hide");

    const sleep = (ms: number): Promise<void> => new Promise((res) => setTimeout(res, ms));

    // do a quick sync
    await page.waitForSelector("#content > p > a");
    await page.click("#content > p > a");
    await sleep(1500);
    // click on 'Photos' menu
    await page.waitForSelector("#menubar > dl:nth-child(2) > dt > span");
    await page.click("#menubar > dl:nth-child(2) > dt > span");
    await sleep(1500);
    // click on 'Add' button
    await page.waitForSelector("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(1) > a");
    await page.click("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(1) > a");
    await sleep(1500);
    // click on 'Tags' button
    await page.waitForSelector("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(2) > a");
    await page.click("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(2) > a");
    await sleep(1500);
    // click on 'Recent photos'
    await page.waitForSelector("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(3) > a");
    await page.click("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(3) > a");
    await sleep(1500);
    // click on 'Batch Manager'
    await page.waitForSelector("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(4) > a");
    await page.click("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(4) > a");
    await sleep(1500);
    // click on 'Caddie'
    await page.waitForSelector("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(5) > a");
    await page.click("#menubar > dl:nth-child(2) > dd > ul > li:nth-child(5) > a");
    await sleep(1500);

    // click on 'Albums' menu
    await page.waitForSelector("#menubar > dl:nth-child(3) > dt > span");
    await page.click("#menubar > dl:nth-child(3) > dt > span");
    await sleep(1500);
    // click on 'Manage' button
    await page.waitForSelector("#menubar > dl:nth-child(3) > dd > ul > li:nth-child(1) > a");
    await page.click("#menubar > dl:nth-child(3) > dd > ul > li:nth-child(1) > a");
    await sleep(1500);
    // click on 'Properties'
    await page.waitForSelector("#menubar > dl:nth-child(3) > dd > ul > li:nth-child(2) > a");
    await page.click("#menubar > dl:nth-child(3) > dd > ul > li:nth-child(2) > a");
    await sleep(1500);

    // click on 'Users' menu
    await page.waitForSelector("#menubar > dl:nth-child(4) > dt > span");
    await page.click("#menubar > dl:nth-child(4) > dt > span");
    await sleep(1500);
    // click on 'Manage' button
    await page.waitForSelector("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(1) > a");
    await page.click("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(1) > a");
    await sleep(1500);
    // click on 'Groups' button
    await page.waitForSelector("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(2) > a");
    await page.click("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(2) > a");
    await sleep(1500);
    // click on 'Notification' button
    await page.waitForSelector("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(3) > a");
    await page.click("#menubar > dl:nth-child(4) > dd > ul > li:nth-child(3) > a");
    await sleep(1500);

    // click on 'Plugins' menu
    await page.waitForSelector("#menubar > dl:nth-child(5) > dt > a > span");
    await page.click("#menubar > dl:nth-child(5) > dt > a > span");
    await sleep(1500);

    // click on 'Tools' menu
    await page.waitForSelector("#menubar > dl:nth-child(6) > dt > span");
    await page.click("#menubar > dl:nth-child(6) > dt > span");
    await sleep(1500);
    // click on 'Synchronize' button
    await page.waitForSelector("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(1) > a");
    await page.click("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(1) > a");
    await sleep(1500);
    // click on 'History' button
    await page.waitForSelector("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(2) > a");
    await page.click("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(2) > a");
    await sleep(1500);
    // click on 'Maintenance' button
    await page.waitForSelector("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(3) > a");
    await page.click("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(3) > a");
    await sleep(1500);
    // click on 'Updates' button
    await page.waitForSelector("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(4) > a");
    await page.click("#menubar > dl:nth-child(6) > dd > ul > li:nth-child(4) > a");
    await sleep(1500);

    // click on 'Configuration' menu
    await page.waitForSelector("#menubar > dl:nth-child(7) > dt > span");
    await page.click("#menubar > dl:nth-child(7) > dt > span");
    await sleep(1500);
    // click on 'Options' button
    await page.waitForSelector("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(1) > a");
    await page.click("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(1) > a");
    await sleep(1500);
    // click on 'Menus' button
    await page.waitForSelector("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(2) > a");
    await page.click("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(2) > a");
    await sleep(1500);
    // click on 'Languages' button
    await page.waitForSelector("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(3) > a");
    await page.click("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(3) > a");
    await sleep(1500);
    // click on 'Themes' button
    await page.waitForSelector("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(4) > a");
    await page.click("#menubar > dl:nth-child(7) > dd > ul > li:nth-child(4) > a");
    await sleep(1500);

    // Gallery
    // go to homepage
    await page.waitForSelector("#pwgHead > a");
    await page.click("#pwgHead > a");
    await sleep(1500);
    // open album
    await page.waitForSelector("#content > ul > li");
    await page.click("#content > ul > li");
    await sleep(1500);
    // open image
    await page.waitForSelector("#thumbnails > li:nth-child(1)");
    await page.click("#thumbnails > li:nth-child(1)");
    await sleep(1500);

    await browser.close();
}

async function handleRequest(interceptedRequest: HTTPRequest): Promise<void> {
    if (interceptedRequest.method() === "POST") {
        // Uncomment to log POST data
        // console.log('POST Data:', interceptedRequest.postData());
    }

    interceptedRequest.continue();
}

async function handleConsoleMessage(message: ConsoleMessage): Promise<void> {
    const type = message.type();
    const text = message.text();

    if (text.startsWith("JQMIGRATE: Migrate is installed with logging active") ) {
        return;
    }

    const colors: { [key: string]: (text: string) => string } = {
        log: gray,
        info: cyan,
        error: red,
        warn: yellow,
    };
    const color = colors[type] || magenta;

    if (type !== "trace") {
        console.log(color(`${type.toUpperCase()} ${text}`));
    }

    for (const arg of message.args()) {
        if (arg.toString().startsWith("JSHandle@object")) {
            try {
                const value = await arg.jsonValue();
                console.log(value);
            } catch (error) {
                console.log(`***${arg.toString()}`);
            }
        }
    }

    const stackTrace = message.stackTrace();

    for (const frame of stackTrace) {
        console.log(`    at ${frame.url}:${(frame.lineNumber ?? 0) + 1}:${(frame.columnNumber ?? 0) + 1}`);
    }

    console.log("");
}

async function clearDirectoryExcept(
    dir: string,
    exclude?: string,
): Promise<void> {
    const dirExists = await fs.pathExists(dir);

    if (!dirExists) {
        return;
    }

    const items = await fs.readdir(dir);

    for (const item of items) {
        const itemPath = path.join(dir, item);

        if (itemPath !== exclude) {
            await fs.remove(itemPath);
        }
    }
}
