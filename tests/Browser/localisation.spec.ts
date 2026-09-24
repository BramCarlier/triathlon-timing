import {test, expect, type Page} from '@playwright/test';
import nl from '../../lang/nl.json' with { type: 'json' };

async function dutch(page:Page) {
    await page.getByRole('combobox', {name:'Language',exact:true}).selectOption('nl');
    await expect(page.locator('html')).toHaveAttribute('lang','nl');
    await expect(page.getByRole('combobox', {name:'Taal',exact:true})).toHaveValue('nl');
}
async function login(page:Page, email='admin@example.test') {
    await page.goto('/login');
    await page.getByRole('textbox',{name:'Email',exact:true}).fill(email);
    await page.getByLabel('Password',{exact:true}).fill('browser-test-password-123');
    await page.getByRole('button',{name:/sign in|log in/i}).click();
    await expect(page).not.toHaveURL(/\/login/);
}

test('guest language persists across login, public timing and filtered results on mobile',async({page})=>{
    await page.setViewportSize({width:375,height:812});
    await page.goto('/login');
    await dutch(page);
    await page.reload();
    await expect(page.getByRole('button',{name:nl['Log in'],exact:true})).toBeVisible();
    await page.goto('/race/responsive-timing');
    await expect(page.getByRole('heading',{name:nl['Choose the checkpoint, then tap the athlete']})).toBeVisible();
    await expect(page.getByRole('combobox',{name:nl['1. Checkpoint'],exact:true})).toBeVisible();
    await expect(page.getByText(nl['Solo athlete'],{exact:true})).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Tap the correct athlete once.');
    await page.getByRole('combobox',{name:nl['1. Checkpoint'],exact:true}).selectOption({label:'Finish'});
    const selected=await page.getByRole('combobox',{name:nl['1. Checkpoint'],exact:true}).inputValue();
    await page.getByRole('combobox',{name:'Taal',exact:true}).selectOption('en');
    await expect(page.getByRole('combobox',{name:'1. Checkpoint',exact:true})).toHaveValue(selected);
    await dutch(page);
    await page.goto('/live/responsive-public');
    const response=page.waitForResponse(r=>r.url().includes('/live/responsive-public?')&&r.request().headers()['x-inertia']==='true');
    await page.getByRole('combobox',{name:nl['Result status'],exact:true}).selectOption('FINISHED');
    expect((await response).ok()).toBeTruthy();
    await expect(page).toHaveURL(/status=FINISHED/);
    await expect(page.getByRole('combobox',{name:nl['Result status'],exact:true})).toHaveValue('FINISHED');
    await expect(page.locator('body')).toContainText(nl['FINISHED']);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBeTruthy();
    await page.getByRole('combobox',{name:'Taal',exact:true}).selectOption('en');
    await expect(page.locator('html')).toHaveAttribute('lang','en');
    await expect(page.getByRole('combobox',{name:'Result status',exact:true})).toHaveValue('FINISHED');
});

test('Dutch organizer can create a default course and add a checkpoint with stable stored codes',async({page})=>{
    await login(page);
    await dutch(page);
    await page.goto('/races/create');
    await page.getByLabel(nl['Race name'],{exact:true}).fill('Dutch localisation regression');
    await page.getByRole('button',{name:nl['Create and continue'],exact:true}).click();
    await expect(page).toHaveURL(/\/races\/\d+$/);
    const prepare=page.locator('#prepare > button').first();
    if(await prepare.getAttribute('aria-expanded')!=='true')await prepare.click();
    await expect(page.getByText(nl['Swim Exit'],{exact:true}).first()).toBeVisible();
    await expect(page.getByText('Lopen: 8 km · 44 km totaal',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:nl['Add checkpoint'],exact:true}).click();
    const form=page.locator('form').filter({has:page.getByRole('heading',{name:nl['Add checkpoint'],exact:true})});
    await expect(form.getByLabel(nl['What happens here?'],{exact:true})).toHaveValue('split');
    await form.getByLabel(nl['Name'],{exact:true}).fill('Custom name stays unchanged');
    await form.getByLabel(nl['Distance into this sport (km)'],{exact:false}).fill('4');
    const request=page.waitForRequest(r=>r.method()==='POST'&&/\/checkpoints$/.test(r.url()));
    await form.getByRole('button',{name:nl['Add checkpoint'],exact:true}).click();
    expect((await request).postDataJSON().kind).toBe('split');
    await expect(page.getByText(nl['Checkpoint added.'],{exact:true})).toBeVisible();
    await expect(page.getByText('Custom name stays unchanged',{exact:true}).first()).toBeVisible();
});

for (const [email,role,heading] of [
    ['admin@example.test','Organizer (admin)','Create the race'],
    ['responsive-official@example.test','Official','Open your race'],
    ['responsive-athlete@example.test','Athlete','Your races'],
] as const) {
    test(`Dutch ${role} guide and navigation`,async({page})=>{
        await login(page,email);
        await page.getByRole('link',{name:'Help',exact:true}).click();
        await expect(page).toHaveURL(/\/guide$/);
        await dutch(page);
        await expect(page.getByRole('heading',{level:1})).toHaveText(nl[':role guide'].replace(':role',nl[role]));
        await expect(page.getByRole('heading',{name:nl[heading],exact:true})).toBeVisible();
        await page.goBack();
        await expect(page.getByRole('combobox',{name:'Taal',exact:true})).toHaveValue('nl');
        await expect(page.locator('html')).toHaveAttribute('lang','nl');
        if(role==='Official'){
            await page.goto('/races/9001/station');
            await expect(page.getByText(nl['Your recent taps'],{exact:true})).toBeVisible();
            await expect(page.locator('body')).toContainText(nl['Everything saved']);
            await page.getByText(nl['Device & sync'],{exact:true}).click();
            await expect(page.getByText(nl['Offline recovery is ready. You normally do not need anything here.'],{exact:true})).toBeVisible();
        }
    });
}
