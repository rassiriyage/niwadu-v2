// Synthetic, loopback-only HTTP contract fixture. Never imported by application code.
import { createServer } from 'node:http';
let withdrawn = false;
let unavailable = false;
const types = [{key:'hotel',label:'Hotel'},{key:'villa',label:'Villa'}];
const capabilities = {filters:['q','property_types'],sorts:['name'],availability_search:false,price_sort:false};
const rows = Array.from({length:26},(_,i)=>({id:i+1,slug:`qa-fixture-${i+1}`,name:`QA fixture ${String(i+1).padStart(2,'0')}`,description:'Synthetic public metadata for isolated testing only.',property_type:i%2?'villa':'hotel',city:'Test city',country:'LK',photo:null}));
createServer((req,res)=>{
 const url = new URL(req.url,'http://127.0.0.1');
 res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
 const send=(status,body)=>{res.statusCode=status;res.end(JSON.stringify(body));};
 if(url.pathname==='/__reset'){withdrawn=false;unavailable=false;return send(200,{});}
 if(url.pathname==='/__withdraw'){withdrawn=true;return send(200,{});}
 if(url.pathname==='/__unavailable'){unavailable=true;return send(200,{});}
 if(unavailable)return send(503,{message:'Fixture unavailable'});
 if(url.pathname==='/api/v1/public/discovery-options')return send(200,{data:{property_types:types,capabilities}});
 const data=rows.filter(r=>!(withdrawn&&r.id===1));
 if(url.pathname==='/api/v1/public/hotels'){
  const params=url.searchParams;const filters=params.getAll('property_types[]');
  if(params.get('sort')!=='name'||filters.some(t=>!types.some(v=>v.key===t)))return send(422,{message:'Unsupported filters'});
  const filtered=data.filter(r=>(!params.get('q')||r.name.toLowerCase().includes(params.get('q').toLowerCase()))&&(!filters.length||filters.includes(r.property_type)));
  const page=Number(params.get('page')||1);
  return send(200,{data:filtered.slice((page-1)*24,page*24),meta:{current_page:page,last_page:Math.max(1,Math.ceil(filtered.length/24)),total:filtered.length,per_page:24,capabilities}});
 }
 const row=data.find(r=>url.pathname===`/api/v1/public/hotels/${r.slug}`);
 return row?send(200,{data:row}):send(404,{message:'Not found'});
}).listen(8099,'127.0.0.1');
