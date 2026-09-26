import {test,expect} from '@playwright/test';
test('QA image drag scrolls the carousel without activating a card',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto('/');
 const track=page.locator('#destination-inspiration');await track.scrollIntoViewIfNeeded();const image=track.locator('.card-image img').nth(2);
 await expect(image).toBeVisible();const b=(await image.boundingBox())!;
 const before=await track.evaluate(e=>e.scrollLeft);
 await page.mouse.move(b.x+b.width/2,b.y+b.height/2);await page.mouse.down();await page.mouse.move(b.x+b.width/2-260,b.y+b.height/2,{steps:12});await page.mouse.up();
 await expect.poll(()=>track.evaluate(e=>e.scrollLeft)).toBeGreaterThan(before+150);
 await expect(page.getByRole('dialog')).not.toBeVisible();
});
test('QA card drag suppresses activation; fresh clicks and arrows still work',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto('/');const track=page.locator('#destination-inspiration');
 await track.scrollIntoViewIfNeeded();const heart=track.locator('.listing-card').nth(2);const b=(await heart.boundingBox())!;
 await page.mouse.move(b.x+b.width/2,b.y+b.height/2);await page.mouse.down();await page.mouse.move(b.x-240,b.y+b.height/2,{steps:12});await page.mouse.up();
 await expect.poll(()=>track.evaluate(e=>e.scrollLeft)).toBeGreaterThan(150);await expect(page.getByRole('dialog')).not.toBeVisible();
 await heart.click();await expect(page.getByRole('dialog')).toBeVisible();await page.keyboard.press('Escape');
 await track.locator('.listing-card').nth(2).click();await expect(page.getByRole('dialog')).toBeVisible();await page.keyboard.press('Escape');
 const previous=page.getByRole('button',{name:'Previous Destination inspiration',exact:true});await previous.click();
 await expect.poll(()=>track.evaluate(e=>e.scrollLeft)).toBeLessThan(150);
});
test('QA release outside the row stops dragging and keeps fresh clicks working',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto('/');const track=page.locator('#destination-inspiration');await track.scrollIntoViewIfNeeded();const b=(await track.locator('.card-image').nth(2).boundingBox())!;
 await page.mouse.move(b.x+50,b.y+50);await page.mouse.down();await page.mouse.move(b.x-170,b.y+50,{steps:10});await page.mouse.move(100,700,{steps:6});await page.mouse.up();
 const stopped=await track.evaluate(e=>e.scrollLeft);await page.mouse.move(900,300,{steps:8});expect(await track.evaluate(e=>e.scrollLeft)).toBe(stopped);
 await track.locator('.listing-card').nth(3).click();await expect(page.getByRole('dialog')).toBeVisible();
});
test('QA native mobile touch swipe and vertical page scrolling remain available',async({browser, baseURL})=>{
 const ctx=await browser.newContext({baseURL,viewport:{width:390,height:844},isMobile:true,hasTouch:true});const page=await ctx.newPage();await page.goto('/');const track=page.locator('#destination-inspiration');const cdp=await ctx.newCDPSession(page);
 async function swipe(x:number,y:number,endX:number,endY:number){await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});for(let i=1;i<=12;i++)await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:x+(endX-x)*i/12,y:y+(endY-y)*i/12}]});await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});}
 await track.scrollIntoViewIfNeeded();const bounds=(await track.boundingBox())!;const y=bounds.y+Math.min(bounds.height/2,150);await swipe(320,y,65,y);await expect.poll(()=>track.evaluate(e=>e.scrollLeft)).toBeGreaterThan(100);await expect(page.getByRole('dialog')).not.toBeVisible();
 const beforeScroll=await page.evaluate(()=>scrollY);await swipe(180,650,180,250);await expect.poll(()=>page.evaluate(()=>scrollY)).toBeGreaterThan(beforeScroll+50);await ctx.close();
});
test('QA pointer cancellation ends drag and keyboard activation remains usable',async({page})=>{
 await page.setViewportSize({width:1440,height:900});await page.goto('/');const track=page.locator('#destination-inspiration');await track.scrollIntoViewIfNeeded();const b=(await track.locator('.card-image').nth(2).boundingBox())!;
 await page.mouse.move(b.x+50,b.y+50);await page.mouse.down();await page.mouse.move(b.x-170,b.y+50,{steps:10});await expect(track).toHaveAttribute('data-dragging','true');
 await track.dispatchEvent('pointercancel',{pointerId:1,pointerType:'mouse',isPrimary:true});await expect(track).not.toHaveAttribute('data-dragging','true');const stopped=await track.evaluate(e=>e.scrollLeft);await page.mouse.move(b.x-300,b.y+50);expect(await track.evaluate(e=>e.scrollLeft)).toBe(stopped);await page.mouse.up();
 await track.locator('.listing-card').nth(2).focus();await page.keyboard.press('Enter');await expect(page.getByRole('dialog')).toBeVisible();
});
