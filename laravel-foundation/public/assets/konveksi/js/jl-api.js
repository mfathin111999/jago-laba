(function () {
 'use strict';
 const script = document.currentScript;
 const API = new URL('../../../api/rpc.php',script.src).href;
 let resetEpoch='';const versions={};let queue=Promise.resolve();
 const read=new Set(['prosesLoginBackend','loadSemuaData','getRiwayatBackupJagoLaba','getGeminiApiKeyStatusServerRpc']);
 function normalizeError(error){return error instanceof Error?error:new Error(error?.message||String(error||'Kesalahan server.'));}
 function request(method,args){
  const send=async()=>{
   const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify([window.JL_SESSION?.name||'',method,args])));
   const signature=Array.from(new Uint8Array(bytes),b=>b.toString(16).padStart(2,'0')).join('');
   const cacheKey='jl-konveksi-pending-'+method;let pending;
   try{pending=JSON.parse(sessionStorage.getItem(cacheKey)||'null');}catch(_){}
   if(!pending||pending.signature!==signature)pending={signature,key:crypto.randomUUID()};
   if(!read.has(method))try{sessionStorage.setItem(cacheKey,JSON.stringify(pending));}catch(_){}
   const response=await fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.JL_SESSION?.csrf||'','X-Idempotency-Key':pending.key,'X-JL-Collection-Versions':JSON.stringify(versions),'X-JL-Reset-Epoch':resetEpoch},body:JSON.stringify({method:String(method),args:args||[]})});
   let body;try{body=await response.json();}catch(_){throw new Error('Status penyimpanan belum diketahui. Periksa koneksi dan muat ulang data sebelum mengulang.');}
   if(!response.ok||body?.ok!==true){const explanation=body?.error?.message||(response.status===401?'Sesi login berakhir. Silakan login kembali.':response.status===403?'Akses atau token keamanan tidak valid. Muat ulang halaman.':'Permintaan belum dapat diproses (HTTP '+response.status+').');const error=new Error(explanation+(body?.error?.request_id?' · ID '+body.error.request_id:''));error.status=response.status;throw error;}
   if(body.result?.__meta?.reset_epoch!==undefined)resetEpoch=String(body.result.__meta.reset_epoch);
   if(body.result?.__meta?.versions)Object.assign(versions,body.result.__meta.versions);
   if(body.result?.__meta)window.dispatchEvent(new CustomEvent('jl:konveksi-status',{detail:body.result.__meta}));
   if(!read.has(method))try{sessionStorage.removeItem(cacheKey);}catch(_){}
   return body.result;
  };
  // Serialize mutations in this tab so each one uses the latest acknowledged versions.
  if(read.has(method))return send();const result=queue.then(send,send);queue=result.catch(()=>{});return result;
 }
 function runner(state={success:null,failure:null,userObject:null}){return new Proxy({},{get(_target,property){
  if(property==='withSuccessHandler')return handler=>runner({...state,success:handler});
  if(property==='withFailureHandler')return handler=>runner({...state,failure:handler});
  if(property==='withUserObject')return object=>runner({...state,userObject:object});
  if(property==='then')return undefined;
  return (...args)=>{const promise=request(property,args);promise.then(async result=>{await state.success?.(result,state.userObject);if(!read.has(property))window.dispatchEvent(new CustomEvent('jl:konveksi-committed'));}).catch(error=>{if(state.failure)state.failure(normalizeError(error),state.userObject);else window.dispatchEvent(new CustomEvent('jl:save-error',{detail:{message:error.message}}));});return promise;};
 }});}
 let latestMeta=null;
 function status(meta){if(meta)latestMeta=meta;if(!latestMeta)return;const node=document.querySelector('[data-kv-state]');if(node){const text=(latestMeta.database_isolated?'Database Konveksi terpisah':'Penyimpanan kompatibilitas')+' · '+(latestMeta.loaded_at?new Date(latestMeta.loaded_at).toLocaleString('id-ID'):'Data server diperbarui');if(node.textContent!==text)node.textContent=text;}const values={'[data-kv-missing]':'missing_stock_ids','[data-kv-duplicates]':'duplicate_stock_ids','[data-kv-negative]':'negative_stock'};for(const [selector,key]of Object.entries(values)){const e=document.querySelector(selector);if(e){const text=String(latestMeta.health?.[key]??'—');if(e.textContent!==text)e.textContent=text;}}}
 window.addEventListener('jl:konveksi-status',event=>{if(event.detail.loaded_at)status(event.detail);});
 new MutationObserver(()=>status()).observe(document.body,{childList:true,subtree:true});
 window.jlApi={api:API,run:runner(),call:request,ready:true};
})();
