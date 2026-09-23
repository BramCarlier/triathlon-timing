import { test, expect, type Page } from '@playwright/test';
const sizes=[
 {name:'small-phone',width:320,height:568},
 {name:'phone-portrait',width:390,height:844},
 {name:'phone-landscape',width:844,height:390},
 {name:'tablet-portrait',width:768,height:1024},
 {name:'tablet-landscape',width:1024,height:768},
 {name:'desktop',width:1440,height:900},
 {name:'desktop-portrait',width:1080,height:1920},
];
async function layout(page:Page,label:string) {
 await expect(page.locator('body')).not.toContainText('Internal Server Error');
 const dimensions=await page.evaluate(()=>({width:window.innerWidth,scroll:document.documentElement.scrollWidth}));
 expect(dimensions.scroll,`${label}: page must not scroll horizontally`).toBeLessThanOrEqual(dimensions.width+1);
 const offenders=await page.locator('input:not([type=checkbox]):not([type=hidden]),select,textarea').evaluateAll(elements=>elements.filter(el=>el.getClientRects().length && el.getBoundingClientRect().width>0).filter(el=>{const r=el.getBoundingClientRect();return r.left < -1 || r.right > innerWidth+1;}).map(el=>el.outerHTML.slice(0,180)));
 expect(offenders,`${label}: form controls fit`).toEqual([]);
 if(await page.evaluate(()=>matchMedia('(pointer: coarse)').matches)) {
  const smallButtons=await page.getByRole('button').evaluateAll(elements=>elements.filter(el=>el.getClientRects().length && el.getBoundingClientRect().height<43).map(el=>el.textContent));
  expect(smallButtons,`${label}: touch buttons have usable height`).toEqual([]);
 }
}
async function login(page:Page,email:string) {
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Password',{exact:true}).fill('browser-test-password-123');await page.getByRole('button',{name:'Log in',exact:true}).click();await expect(page).not.toHaveURL(/\/login$/);
}
async function menu(page:Page) {
 const button=page.getByRole('button',{name:'Menu',exact:true});
 if(await button.isVisible() && await button.getAttribute('aria-expanded')==='false')await button.click();
}
test('every reachable page fits portrait and landscape with long names, dialogs and touch controls',async({page},info)=>{
 test.setTimeout(300000);
 const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
 for(const size of sizes) {
  await page.setViewportSize(size);
  for(const path of ['/login','/forgot-password','/reset-password/layout-preview?email=preview@example.test&welcome=1']) {
   await page.goto(path);await expect(page.locator('form')).toBeVisible();await layout(page,`${size.name} ${path}`);
   const form=await page.locator('form').boundingBox();const toggle=await page.getByRole('button',{name:/Switch to .* mode/}).boundingBox();
   expect(form!.y,`${size.name}: theme switch must not cover sign-in`).toBeGreaterThanOrEqual(toggle!.y+toggle!.height);
  }
 }
 await page.setViewportSize(sizes[5]);await login(page,'admin@example.test');
 const paths=['/races','/races/create','/races/9001','/races/9002','/races/9003','/races/9001/participants','/races/9001/participants/9001/edit','/races/9001/participants/import','/races/9001/control','/races/9002/control','/races/9003/control','/races/9001/results','/users','/users/9001/edit','/admin/roles','/admin/health','/account/password'];
 for(const size of sizes) {
  await page.setViewportSize(size);
  await page.emulateMedia({colorScheme:size.width%2===0?'dark':'light'});
  for(const path of paths) {
   await page.goto(path);await expect(page.locator('h1')).toBeVisible();await layout(page,`${size.name} ${path}`);
   if([320,844,1440].includes(size.width)&&['/races/9001/participants','/races/9001/control','/admin/roles'].includes(path))await page.screenshot({path:info.outputPath(`responsive-${size.name}-${path.split('/').pop()}.png`),fullPage:true});
  }
  await menu(page);await expect(page.getByRole('link',{name:'Roles & permissions',exact:true})).toBeVisible();await layout(page,`${size.name} open menu`);
  await page.getByRole('link',{name:'Roles & permissions',exact:true}).click();
  if(size.width<1280)await expect(page.getByRole('button',{name:'Menu',exact:true})).toHaveAttribute('aria-expanded','false');
  // Native dialog remains within the short landscape viewport; both actions are reachable.
  await page.goto('/races/9001');await page.getByRole('button',{name:'Delete race',exact:true}).click();
  const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();const box=await dialog.boundingBox();
  expect(box!.height).toBeLessThanOrEqual(size.height-16);expect(box!.width).toBeLessThanOrEqual(size.width-16);
  await dialog.getByRole('button',{name:'Cancel',exact:true}).click();await expect(dialog).toHaveCount(0);
  await page.goto('/races/9001/station');
  if(await page.getByRole('button',{name:'Start checkpoint mode'}).isVisible())await page.getByRole('button',{name:'Start checkpoint mode'}).click();
  await expect(page.getByLabel('Find participant')).toBeVisible();await layout(page,`${size.name} selected station`);
  await page.getByLabel('Find participant').fill('TheLongestRelay');await expect(page.getByRole('button',{name:/TheLongestRelay.*TAP/})).toBeVisible();
  await page.screenshot({path:info.outputPath(`responsive-${size.name}-station.png`),fullPage:true});
  await page.goto('/races/9001/results');await page.getByLabel('Timing precision').selectOption('3');
  await page.getByRole('button',{name:'View splits',exact:true}).first().click();await expect(page.getByText('split 00:16:40.234',{exact:true}).filter({visible:true})).toBeVisible();await layout(page,`${size.name} expanded results`);
  await page.screenshot({path:info.outputPath(`responsive-${size.name}-results.png`),fullPage:true});
  await page.getByRole('button',{name:'Large-screen display'}).click();await layout(page,`${size.name} display results`);await page.getByRole('button',{name:'Exit display mode'}).click();
  await page.goto('/users/9001/edit');await page.getByLabel('Role',{exact:true}).selectOption('athlete');await expect(page.getByLabel('Link athlete')).toBeVisible();await layout(page,`${size.name} athlete picker`);
  await page.goto('/races/9001/participants/import');await page.getByLabel('Participant file').setInputFiles({name:'long-participant-import-preview.csv',mimeType:'text/csv',buffer:Buffer.from('type,first_name,last_name\nsolo,'+'LongName'.repeat(10)+',Runner\n')});await page.getByRole('button',{name:'Preview file',exact:true}).click();await expect(page.getByRole('button',{name:'Confirm import'})).toBeVisible();await layout(page,`${size.name} import preview`);
 }
 await page.setViewportSize(sizes[5]);await page.goto('/races');await page.getByRole('button',{name:'Log out',exact:true}).click();
 await login(page,'responsive-athlete@example.test');
 for(const size of sizes){await page.setViewportSize(size);await page.goto('/athlete');await expect(page.locator('h1')).toContainText('Welcome');await layout(page,`${size.name} athlete dashboard`);await page.goto('/races/9001/results');await layout(page,`${size.name} athlete results`);}
 await page.setViewportSize(sizes[5]);await menu(page);await page.getByRole('button',{name:'Log out',exact:true}).click();
 for(const size of sizes){await page.setViewportSize(size);await page.goto('/live/responsive-public');await expect(page.locator('h1')).toContainText('Results');await layout(page,`${size.name} public results`);}
 expect(errors).toEqual([]);
});
