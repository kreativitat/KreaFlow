/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
// Browser acceptance uses synthetic API responses, never live business records.
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.KREAFLOW_REVIEW_URL || 'http://127.0.0.1:4178';
const output = new URL('../artifacts/ui-redesign/', import.meta.url).pathname;
fs.mkdirSync(output, { recursive: true });
const rights = Object.fromEntries(['api_read','dashboard_read','eats_read','kitchen_read','stock_forecast_read','products_read','workforce_schedule_read','workforce_punch_read','fecho_read','fecho_create','fecho_update','fecho_update_validated','fecho_update_old','history_readall','facture_read','product_read'].map(key => [key, 1]));
const session = {user:{id:77, login:'review', fullname:'UI review fixture', email:''}, entity:2, entity_label:'STORE02', can_switch_entity:0, available_entities:[], rights:{...rights, history_limit_days:365,fecho_edit_limit_days:7},token:'fixture-only'};
const dashboard = {
  period:{date_start:'2026-10-07',date_end:'2026-10-07',day_count:1},
  kpis:{total_revenue_ht:719.93,total_revenue_ttc:819.40,total_qty_sold:323,total_invoices:40,total_people:53,avg_per_day_ht:719.93,avg_per_day_ttc:819.40,avg_ticket_ht:13.58,avg_ticket_ttc:15.46},
  previous_year_sales:{comparison_mode:'operational',date_start:'2025-10-08',date_end:'2025-10-08',status:'available',revenue_ht:681.49,revenue_ttc:785.13},
  daily_sales:[1250,1800,2400,1750,1610,210,819.4].map((v,i)=>({sale_date:`2026-10-0${i+1}`,revenue_ht:v/1.14,revenue_ttc:v,qty_sold:30,invoice_count:5,people_count:8})),
  hourly_sales:[11,12,13,14,15,19,20,21].map((h,i)=>({hour_of_day:h,revenue_ttc:[20,86,345,74,18,24,152,25][i]})),
  sales_by_payment_type:[{paymenttype_name:'Cartão',transaction_count:24,total_liquido:450.1,total_gross:512.3},{paymenttype_name:'Numerário',transaction_count:16,total_liquido:269.83,total_gross:307.1}],
  top_products:[{product_ref:'REV01',product_label:'Produto de revisão com descrição longa para testar adaptação',qty_sold:323,revenue_ht:719.93,revenue_ttc:819.4}],top_products_total:1,top_products_page:1,top_products_page_size:10,top_products_pages:1,
  sales_by_employee:[{employee_name:'Utilizador de revisão',transaction_count:40,total_quantity_sold:323,total_sales_value_ht:719.93,total_sales_value_ttc:819.4}]
};
const fixtureDate='2026-10-09';
const fixtures = {
  product_categories: [{id:1,label:'Categoria de revisão',color:'#e63d00',product_count:1}],
  products: [{id:1,ref:'REV01',label:'Produto de revisão',price_ht:10,price_ttc:11.3,tva_tx:13}],
  workforce: {period:{week_start:'2026-10-05',week_end:'2026-10-11',day:'2026-10-08',punch_date:'2026-10-08',day_start_minutes:0,day_end_minutes:1440},permissions:{schedule_read:1,punch_read:1},resources:[],events:[],punch_rows:[],dayoffs:[],leaves:[],public_holidays:[]},
  fechofinanceiro: {period:{date_start:'2026-10-01',date_end:'2026-10-08',limit_date:'2026-01-01'},count:1,totals:{total_sem_iva:719.93,total_talao_caixa:819.4},rows:[{id:1,ref:'REV01',datecx:'2026-10-07',store_id:2,store_label:'STORE02',responsavel_id:77,responsavel_label:'UI review fixture',total_sem_iva:719.93,val_talao_caixa:819.4,status:0,status_label:'Rascunho'}]},
  fecho_meta: {permissions:{can_create:1,can_update:1,can_write:1,can_change_store:0,can_update_validated:0,can_update_old:0,edit_limit_days:7},defaults:{datecx:'2026-10-08',fk_loja:2,responsavel:77},stores:[{id:2,label:'STORE02'}],users:[{id:77,label:'UI review fixture'}]},
  sales_forecast: {period:{date_start:'2026-10-01',date_end:'2026-10-07',day_count:7,chart_start:'2026-10-01',chart_days:9,training_start:'2026-01-01',training_end:'2026-10-07',training_days:280,forecast_days:2},kpis:{historical_total_ht:719.93,historical_total_ttc:819.4,historical_avg_ht:100,historical_avg_ttc:114,forecast_total_ht:1500,forecast_total_ttc:1700,forecast_avg_ht:750,forecast_avg_ttc:850},chart_rows:[...dashboard.daily_sales.map(d=>({date:d.sale_date,revenue_ttc:d.revenue_ttc,series:'historical'})),{date:fixtureDate,revenue_ttc:850,series:'forecast'}],forecast_rows:[{date:fixtureDate,forecast_ht:750,forecast_ttc:850,temp_mean:20,precip_mm:0,precip_intensity:'Fraca',precip_probability:'Baixa',precip_probability_pct:10}],meta:{model:'fixture',has_weather_history:1,has_weather_forecast:1}},
  stock_forecast: {category:{id:1,label:'PATTIES'},inventory:{id:1,ref:'REV01',date_inventory:1791417600,warehouse_id:2,warehouse_ref:'STORE02',snapshot_time:'2026-10-08'},period:{history_start:'2026-09-01',history_end:'2026-10-07',history_days:37,forecast_days:1},summary:{product_count:1,at_risk_products:0,horizon_at_risk_products:0,forecast_sales_ttc:850},products:[{product_id:1,ref:'REV01',label:'Produto de revisão',counted_stock:100,inventory_snapshot_stock:100,current_stock:100,forecast_required:40,projected_stock:60,shortage:0,next_delivery_date:null,next_delivery_required:0,next_delivery_projected_stock:60,next_delivery_shortage:0,overall_units_per_euro:.05,daily:[{date:fixtureDate,forecast_sales_ttc:850,units_per_euro:.05,required_quantity:40}],deliveries:[]}],meta:{model:'fixture',safety_stock_included:0,planned_receipts_included:0,delivery_weekdays:[],no_delivery_weekdays:[]}},
  eats_week: {entity:2,token:'fixture',analysis_access:false,analysis_available:false,period:{start:'2026-09-28',end:'2026-10-04',limited:false,previous:true,next:true},economics:{has_data:true,sales:80000,fees:20000,net:60000,adjustments:0,fee_rate:2500,commission_rate:2500,retained_rate:2500,difference:0},costs:{known_cost:70000,profit:-10000,matched:10,total:10,partial:false,state:'available'},analytics:{invoices:10,sales:80000,average:8000,can_products:true,days:[{date:'2026-09-28',sales:80000,invoices:10}],products:[{rowid:1,ref:'REV01',label:'Produto de revisão',quantity:20,amount:80000}]},payouts:[],excluded_dates:0},
  kitchen: {entity:2,generated_at:new Date().toISOString(),sampled_at:new Date().toISOString(),received_at:new Date().toISOString(),fresh:true,limited:false,supported_states:['preparing','ready'],lifecycle_verified:true,timing_configured:true,complete_queue:false,window_hours:24,note:'fixture',day:'2026-10-08',timezone:'Europe/Lisbon',stations:[{id:'1',name:'MONTAGEM'}],counts:{preparing:1,waiting:0,ready:0,unknown:0},items:[{id:'1',order:'77',table:'12',zone:'1',zone_name:'Sala',product:'REV01',label:'Produto de revisão',quantity:2,centre:'1',centre_name:'MONTAGEM',status:'preparing',raw_status:'1',elapsed_seconds:120,duration_seconds:null,started_at:new Date().toISOString()}],products:[],timings:{samples:10,eligible_lines:10,average_seconds:120,p90_seconds:180}}
};
const browser=await chromium.launch({headless:true});
const errors=[]; const requests=[]; const results=[];
try {
  const context=await browser.newContext({serviceWorkers:'block',locale:'pt-PT',timezoneId:'Europe/Lisbon'});
  await context.route('**/mobile.php?**', async route=>{
    const url=new URL(route.request().url()); const action=url.searchParams.get('kf_action'); requests.push({action,params:Object.fromEntries(url.searchParams)});
    let data;
    if(action==='whoami') data=session;
    else if(action==='dashboard') data={...dashboard,period:{...dashboard.period,date_start:url.searchParams.get('date_start'),date_end:url.searchParams.get('date_end')}};
    else if(action==='login_meta') data={default_entity:2,entities:[]};
    else if(fixtures[action]) data=fixtures[action];
    else return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({success:false,error:{message:'UI fixture: service unavailable'}})});
    await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
  });
  const page=await context.newPage();
  page.on('pageerror',error=>errors.push(error.message));
  for(const width of [1622,1280,768,390,320]) {
    await page.setViewportSize({width,height:1000});
    await page.goto(base+'/custom/kreaflow/frontend/');
    await page.locator('.stat-card h2').first().waitFor();
    await page.waitForTimeout(1800);
    const layout=await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth,bg:getComputedStyle(document.body).backgroundColor,overflow:[...document.querySelectorAll('.card,.filter-grid,.kpi-grid,.chart-box,.data-table')].filter(el=>el.getBoundingClientRect().right>innerWidth+1).map(el=>el.className)}));
    assert.equal(layout.document,width,`document overflow at ${width}`);
    assert.equal(layout.overflow.length,0,`content overflow at ${width}: ${layout.overflow}`);
    assert.equal(layout.bg,'rgb(247, 248, 250)');
    assert.match(await page.locator('.stat-card h2').first().innerText(),/819,40/);
    assert.equal(await page.locator('.main-chart .recharts-line-curve').count(),1);
    assert.equal(await page.locator('.side-chart .recharts-bar-rectangle').count(),8);
    const clippedAmounts = await page.locator('.payment-compact-table td.right').evaluateAll(cells => cells.filter(el => el.scrollWidth > el.clientWidth + 1).length);
    assert.equal(clippedAmounts,0,`clipped payment values at ${width}`);
    await page.locator('.sales-history summary').click();
    assert.match(await page.locator('.sales-history').innerText(),/681,49/);
    await page.locator('.sales-history summary').click();
    await page.screenshot({path:output+`dashboard-${width}.png`,fullPage:true});
    if(width>=1200){
      assert.equal(await page.locator('.kf-sidebar').isVisible(),true);
      await page.getByRole('button',{name:'Recolher menu',exact:true}).click();
      assert.equal(await page.locator('.kf-sidebar.is-collapsed').count(),1);
      await page.getByRole('button',{name:'Expandir menu',exact:true}).click();
    } else {
      const toggle=page.locator('#kf-menu-toggle');
      assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      await toggle.click();
      await page.getByRole('dialog',{name:'Navegação',exact:true}).waitFor();
      assert.equal(await toggle.getAttribute('aria-expanded'),'true');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await page.evaluate(()=>document.activeElement?.closest('.nav-drawer-sheet')!==null),true);
      await page.keyboard.press('Escape');
      assert.equal(await toggle.evaluate(el=>el===document.activeElement),true);
      await toggle.click();
      await page.locator('.nav-drawer-sheet').getByRole('button',{name:'Sobre',exact:true}).click();
      assert.equal(await page.locator('.nav-drawer-modal').count(),0);
      assert.match(page.url(),/tab=about/);
      await toggle.click();
      await page.locator('.nav-drawer-sheet').getByRole('button',{name:'Dashboard',exact:true}).click();
    }
    await page.getByRole('button',{name:'Hoje',exact:true}).click();
    await page.waitForTimeout(100);
    assert.equal(await page.getByRole('button',{name:'Hoje',exact:true}).getAttribute('aria-pressed'),'true');
    const last=requests.filter(r=>r.action==='dashboard').at(-1);
    assert.equal(last.params.date_start,last.params.date_end);
    await page.locator('.date-trigger').first().click();
    assert.equal(await page.locator('input[type=date]').count(),0,'wheel picker retained');
    await page.screenshot({path:output+`date-picker-${width}.png`,fullPage:true});
    results.push({width,...layout,sidebarAndDrawer:'passed',datesAndAmounts:'passed'});
  }
  // Every remaining route must preserve navigation and fit representative synthetic data.
  for (const width of [1280, 768, 320]) {
    await page.setViewportSize({width,height:900});
    for (const tab of ['eats','kitchen','forecast','stock_forecast','produtos','fecho','horarios','barras','ponto','about']) {
      await page.goto(base+'/custom/kreaflow/frontend/?tab='+tab);
      await page.locator('.kf-workspace').waitFor();
      await page.waitForTimeout(500);
      if(width===320) await page.screenshot({path:output+tab+'-320.png',fullPage:true});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width,`${tab} overflow at ${width}`);
      assert.match(page.url(),new RegExp('tab='+tab));
      assert.equal(await page.locator('.status-message').count(),0,`${tab} data must load`);
      if(tab==='eats') {
        assert.match(await page.locator('.eats-profit').innerText(),/-100,00/);
        assert.equal(await page.locator('.eats-profit').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
      }
    }
  }
  const loggedOut=await browser.newContext({viewport:{width:320,height:850},serviceWorkers:'block'});
  await loggedOut.route('**/mobile.php?**',route=>{
    const meta=route.request().url().includes('login_meta');
    return route.fulfill({status:meta?200:401,contentType:'application/json',body:JSON.stringify(meta?{success:true,data:{default_entity:2,entities:[]}}:{success:false,error:{message:'Unauthenticated'}})});
  });
  const login=await loggedOut.newPage(); await login.goto(base+'/custom/kreaflow/frontend/');
  await login.locator('.auth-form').waitFor();
  await login.locator('input[autocomplete=username]').focus();
  assert.equal(await login.locator('input[autocomplete=username]').evaluate(el=>getComputedStyle(el).fontSize),'16px');
  assert.equal(await login.evaluate(()=>document.documentElement.scrollWidth),320);
  await login.screenshot({path:output+'login-320.png',fullPage:true});
  // Permission-filtered navigation must remain authoritative after the shell change.
  const restricted=await browser.newContext({viewport:{width:1280,height:900},serviceWorkers:'block'});
  await restricted.route('**/mobile.php?**', route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{...session,rights:Object.fromEntries(Object.keys(session.rights).map(k=>[k,0]))}})}));
  const denied=await restricted.newPage(); await denied.goto(base+'/custom/kreaflow/frontend/'); await denied.locator('.kf-sidebar').waitFor();
  assert.equal(await denied.locator('.kf-sidebar nav button').count(),1);
  assert.equal(await denied.locator('.kf-sidebar nav button').innerText(),'Sobre');
  assert.equal(await denied.locator('.dashboard-space').count(),0);
  // Load both stylesheets into an unrelated document: no selectors may escape the app marker.
  const isolated=await context.newPage(); await isolated.goto(base+'/');
  await isolated.setContent('<html><body><button class="btn">Unrelated module</button><div class="card">Native content</div></body></html>');
  const before=await isolated.locator('.btn').evaluate(el=>({background:getComputedStyle(el).background,color:getComputedStyle(el).color}));
  await isolated.addStyleTag({url:base+'/custom/kreaflow/css/design-tokens.css'});
  await isolated.addStyleTag({url:base+'/custom/kreaflow/app/src/styles.css'});
  await isolated.addStyleTag({url:base+'/custom/kreaflow/app/src/design-system.css'});
  await isolated.addStyleTag({url:base+'/custom/kreaflow/css/design-system-native.css'});
  assert.deepEqual(await isolated.locator('.btn').evaluate(el=>({background:getComputedStyle(el).background,color:getComputedStyle(el).color})),before);
  assert.deepEqual(errors,[]);
  fs.writeFileSync(output+'results.json',JSON.stringify({results,permissions:'passed',styleIsolation:'passed',errors},null,2));
  console.log(JSON.stringify({viewports:results.map(r=>r.width),permissions:'passed',styleIsolation:'passed',errors},null,2));
} finally { await browser.close(); }
