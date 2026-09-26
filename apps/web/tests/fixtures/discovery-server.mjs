// Synthetic, loopback-only HTTP contract fixture. Never imported by application code.
import { createServer } from 'node:http';
let withdrawn = false;
let expanded = false;
let unavailable = false;
let ratesUnavailable = false;
const types = [{key:'hotel',label:'Hotel'},{key:'villa',label:'Villa'}];
const capabilities = {filters:['q','property_types'],sorts:['name'],availability_search:false,price_sort:false};
const rows = Array.from({length:26},(_,i)=>({id:i+1,slug:`qa-fixture-${i+1}`,name:`QA fixture ${String(i+1).padStart(2,'0')}`,description:'Synthetic public metadata for isolated testing only.',property_type:i%2?'villa':'hotel',city:'Test city',country:'LK',photo:null}));
createServer((req,res)=>{
 const url = new URL(req.url,'http://127.0.0.1');
 res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
 const send=(status,body)=>{res.statusCode=status;res.end(JSON.stringify(body));};
 if(url.pathname==='/__reset'){withdrawn=false;unavailable=false;ratesUnavailable=false;expanded=false;return send(200,{});}
 if(url.pathname==='/__expanded'){expanded=true;return send(200,{});}
 if(url.pathname==='/__withdraw'){withdrawn=true;return send(200,{});}
 if(url.pathname==='/__unavailable'){unavailable=true;return send(200,{});}
 if(url.pathname==='/__rates-unavailable'){ratesUnavailable=true;return send(200,{});}
 if(unavailable)return send(503,{message:'Fixture unavailable'});
 if(url.pathname==='/api/v1/public/discovery-options')return send(200,{data:{property_types:types,capabilities:{...capabilities,sorts:expanded?['name','editorial']:['name']},...(expanded?{destinations:[{key:'galle',label:'Galle'}],districts:[{key:'galle',label:'Galle'}],regions:[{key:'north-east',label:'North & East'}],themes:[{key:'beach',label:'Beach'}],amenities:[{key:'wifi',label:'Wi-Fi'},{key:'pool',label:'Pool'}]}:{})}});
 const data=rows.filter(r=>!(withdrawn&&r.id===1));
 if(url.pathname==='/api/v1/public/hotels'){
  const params=url.searchParams;const filters=params.getAll('property_types[]');
  if(!['name',...(expanded?['editorial']:[])].includes(params.get('sort'))||filters.some(t=>!['hotel','villa','guest_house','resort','apartment','hostel'].includes(t)))return send(422,{message:'Unsupported filters'});
  let filtered=data.filter(r=>(!params.get('q')||r.name.toLowerCase().includes(params.get('q').toLowerCase()))&&(!filters.length||filters.includes(r.property_type)));
  if(expanded){
   if(params.get('destination') && !['galle','ella'].includes(params.get('destination')))return send(422,{message:'Unknown destination'});
   if(params.get('destination')==='ella'||params.get('region')==='north-east')filtered=[];
   if(params.get('destination')==='galle'||params.get('district')==='galle')filtered=filtered.filter(r=>r.id<=13);
   if(params.getAll('themes[]').includes('beach')||params.getAll('amenities[]').includes('pool'))filtered=filtered.filter(r=>r.id%2===1);
   if(params.get('sort')==='editorial')filtered.reverse();
  }
  const page=Number(params.get('page')||1);
  return send(200,{data:filtered.slice((page-1)*24,page*24),meta:{current_page:page,last_page:Math.max(1,Math.ceil(filtered.length/24)),total:filtered.length,per_page:24,capabilities}});
 }
 const ratesHotel=data.find(r=>url.pathname===`/api/v1/public/hotels/${r.slug}/rate-plans`);
 if(ratesHotel){
  if(ratesUnavailable)return send(503,{message:"Unavailable"});
  const arrival=url.searchParams.get('arrival'),departure=url.searchParams.get('departure'),adults=url.searchParams.get('adults');
  const currency=url.searchParams.get('currency')||'LKR';
  const nights=(Date.parse(departure)-Date.parse(arrival))/86400000;
  if(!['LKR','USD'].includes(currency)||!arrival||!departure||!Number.isFinite(nights)||nights<1||nights>30||!/^([1-9]|[12][0-9]|30)$/.test(adults||''))return send(422,{message:'Invalid selection'});
  return send(200,{data:adults==='3'||(currency==='USD'&&arrival==='2030-01-11')?[]:[{rate_plan_id:1,name:'Flexible',room_type_id:1,room_name:'Garden room',max_adults:2,currency,meal_plan:currency==='USD'?'BB':null,total_minor:currency==='USD'?8025:1250500,policy:{version:'1',text:'Synthetic cancellation policy.'},expires_at:new Date(Date.now()+60000).toISOString()}],meta:{inventory_reserved:false,selection:{arrival,departure,adults,currency}}});
 }
 const row=data.find(r=>url.pathname===`/api/v1/public/hotels/${r.slug}`);
 return row?send(200,{data:row}):send(404,{message:'Not found'});
}).listen(8099,'127.0.0.1');
