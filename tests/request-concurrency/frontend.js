'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const { randomUUID } = require('crypto');
const elements = new Map();
function el(id) {
  if (!elements.has(id)) elements.set(id, { id, textContent:'', innerHTML:'', value:'', disabled:false, src:'about:blank', style:{},dataset:{},classList:{contains:()=>false,add(){},remove(){}} });
  return elements.get(id);
}
const input = {value:'Entered draft survives',getAttribute:()=> 'full_name'};
const storage = new Map();
const context = vm.createContext({console,crypto:{randomUUID},setTimeout,clearTimeout,
  document:{addEventListener(){},getElementById:el,querySelectorAll:selector=>selector.includes('data-doc-field')?[input]:[]},
  window:{confirm:()=>true,prompt:()=>null,addEventListener(){},matchMedia:()=>({matches:false,addEventListener(){}})},
  sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
  localStorage:{getItem:()=>null},fetch:async()=>{},URL,URLSearchParams});
vm.runInContext(fs.readFileSync(require('path').join(__dirname,'../../js/official.js'),'utf8'),context);
const run = code=>vm.runInContext(code,context);
let passed = 0;
function check(name, value) { assert.ok(value,name);passed++;console.log('PASS '+name); }
function deferred() { let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject}; }
async function main() {
  context.calls=[];context.toasts=[];
  run(`showToastAdmin=(title,message)=>toasts.push({title,message}); loadRequests=async()=>{};loadAuditLog=async()=>{};refreshDashboard=async()=>{};showRequestDetails=()=>{};featureEnabled=()=>true;`);
  check('row mapping retains server version',run(`mapRequestRow({id:1,row_version:7}).rowVersion===7`));
  const html=run(`requestActionButtonsHtml({ref:'REQ1',rowVersion:7,status:'ready',paymentStatus:'unpaid'})`);
  check('displayed buttons embed the reviewed version',html.includes("updateReqStatus('REQ1','completed','',7)") && html.includes("notifyPickupReady('REQ1',7)"));
  context.gate=deferred();
  run(`fetchJson=async(url,options)=>{calls.push(JSON.parse(options.body));return gate.promise;};`);
  const first=run(`requestAction('update_payment','REQ1',{reference_no:'REQ1',payment_status:'paid',expected_version:7})`);
  const second=await run(`requestAction('update_payment','REQ1',{reference_no:'REQ1',payment_status:'paid',expected_version:7})`);
  check('duplicate click makes one submission',context.calls.length===1 && second===null);
  context.gate.reject(new Error('Lost network response'));await first.catch(()=>{});
  const originalId=context.calls[0].operation_id;
  context.gate=deferred();
  const retry=run(`requestAction('update_payment','REQ1',{reference_no:'REQ1',payment_status:'paid',expected_version:7})`);
  check('uncertain retry preserves operation ID',context.calls[1].operation_id===originalId);
  context.gate.resolve({success:true});await retry;
  context.gate=deferred();
  const next=run(`requestAction('update_payment','REQ1',{reference_no:'REQ1',payment_status:'paid',expected_version:7})`);
  check('genuinely new action has a new operation ID',context.calls[2].operation_id!==originalId);
  context.gate.resolve({success:true});await next;
  context.calls=[];
  run(`DOC_REQUESTS=[{id:1,ref:'REQ1',rowVersion:99,status:'processing',paymentStatus:'paid'}];
    fetchJson=async(url,options)=>{calls.push(JSON.parse(options.body));const e=new Error('stale');e.status=409;e.data={code:'stale_version'};throw e;};
    REQUEST_CANCEL_DRAFTS.set('REQ1','Keep my cancellation reason');`);
  let conflict;
  try {await run(`requestAction('update_status','REQ1',{reference_no:'REQ1',status:'cancelled',official_note:'Keep my cancellation reason',expected_version:7})`);}catch(e){conflict=e;}
  check('polling never substitutes a newer version in an older decision',context.calls[0].expected_version===7 && context.calls.length===1);
  check('409 has prescribed review message and retains cancellation input',conflict.message==='This request changed. Review the current information before trying again.' && run(`REQUEST_CANCEL_DRAFTS.get('REQ1')`)==='Keep my cancellation reason');
  // Verify the real fetchJson HTTP contract in an independent VM using its source.
  const source=fs.readFileSync(require('path').join(__dirname,'../../js/official.js'),'utf8');
  const fetchFunction=source.slice(source.indexOf('async function fetchJson('),source.indexOf('async function loadResidents()'));
  const fetchContext=vm.createContext({fetch:async()=>({ok:false,status:409,text:async()=>JSON.stringify({success:false,code:'stale_version',message:'Changed'})})});
  vm.runInContext(fetchFunction,fetchContext);
  let structured;try{await vm.runInContext(`fetchJson('/fixture')`,fetchContext);}catch(e){structured=e;}
  check('fetchJson preserves HTTP status and structured error',structured.status===409 && structured.data.code==='stale_version');
  run(`loadRequests=async()=>{throw new Error('Offline refresh');};`);
  await run(`requestRefreshAfterSuccess('REQ1')`);
  check('committed success with failed refresh is labelled saved',context.toasts.at(-1).title==='Saved; Refresh Failed');
  context.gate=deferred();
  run(`requestAction=async(endpoint,ref,payload)=>{calls.push({endpoint,ref,payload});return gate.promise;};
       requestRefreshAfterSuccess=async()=>{};
       ACTIVE_DOC_GEN_REQUEST={id:1,ref:'A',rowVersion:3};`);
  const generation=run(`generateAndPreviewDocument()`);
  run(`ACTIVE_DOC_GEN_REQUEST={id:2,ref:'B',rowVersion:8};`);
  el('docGenPreviewFrame').src='B-preview';
  context.gate.resolve({pdf_path:'A.pdf'});await generation;
  check('asynchronous generation stays owned by captured request A',context.calls.at(-1).ref==='A' && context.calls.at(-1).payload.id===1 && el('docGenPreviewFrame').src==='B-preview' && context.toasts.at(-1).message.includes('A'));
  context.gate=deferred();run(`ACTIVE_DOC_GEN_REQUEST={id:1,ref:'A',rowVersion:3};`);
  const closed=run(`generateAndPreviewDocument()`);run(`ACTIVE_DOC_GEN_REQUEST=null;`);context.gate.resolve({pdf_path:'A2.pdf'});await closed;
  check('closing modal does not redirect result',el('docGenPreviewFrame').src==='B-preview');
  run(`DOC_REQUESTS=[{id:1,ref:'A',rowVersion:4,status:'pending',paymentStatus:'paid'}];
       ACTIVE_DOC_GEN_REQUEST={id:1,ref:'A',rowVersion:3};
       requestAction=async()=>{const e=new Error(REQUEST_CONFLICT_MESSAGE);e.status=409;e.data={code:'stale_version'};throw e;};`);
  await run(`generateAndPreviewDocument()`);
  check('generation conflict retains entered fields and demands review',input.value==='Entered draft survives' && run(`ACTIVE_DOC_GEN_REQUEST.needsReview`) && el('generateDocBtn').textContent==='Review Current Request');
  let reviews=0;context.window.confirm=()=>{reviews++;return true;};
  await run(`generateAndPreviewDocument()`);
  check('explicit review adopts only the displayed snapshot without resubmitting',reviews===1 && run(`ACTIVE_DOC_GEN_REQUEST.rowVersion===4 && !ACTIVE_DOC_GEN_REQUEST.needsReview`) && input.value==='Entered draft survives');
  check('generation frontend has no separate Processing status call',!source.slice(source.indexOf('async function generateAndPreviewDocument'),source.indexOf('function initDocumentGenerationModal')).includes('updateReqStatus('));
  console.log(`FRONTEND ${passed} passed, 0 failed`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
