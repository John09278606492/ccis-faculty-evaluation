// Real HTTP sessions + CSRF, isolated SQLite data, loopback listeners only.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawn, spawnSync} = require('node:child_process');
const {performance} = require('node:perf_hooks');
const os = require('node:os');
const args = Object.fromEntries(process.argv.slice(2).map(v => v.replace(/^--/, '').split('=')));
const root = path.resolve(__dirname, '..');
const work = path.resolve(root, args.work || 'storage/app/load-tests/baseline');
if (!work.startsWith(path.join(root, 'storage', 'app', 'load-tests') + path.sep)) throw Error('Use an isolated load-tests subdirectory.');
fs.mkdirSync(work, {recursive:true});
const php = args.php || 'php';
const basePort = Number(args.port || 8200), workers = Number(args.workers || 8);
const concurrency = Number(args.concurrency || 1000), studentCount = Number(args.students || 1000);
const db = path.join(work, 'evaluation.sqlite');
const manifestPath = path.join(work, 'fixture.json');
const env = {...process.env, APP_ENV:'loadtesting', APP_DEBUG:'false', APP_URL:`http://127.0.0.1:${basePort}`,
    APP_MAINTENANCE_DRIVER:'cache', APP_MAINTENANCE_STORE:'file',
    DB_CONNECTION:'sqlite', DB_DATABASE:db, DB_URL:'', SESSION_DRIVER:args.sessions || 'database', CACHE_STORE:args.cache || 'database',
    CACHE_PREFIX:'load-'+path.basename(work), CACHE_FILE_PATH:path.join(work,'cache'), SESSION_FILES:path.join(work,'sessions'),
    SESSION_COOKIE:'load_evaluation_session', QUEUE_CONNECTION:'sync', BROADCAST_CONNECTION:'null', BCRYPT_ROUNDS:'4', LOG_LEVEL:'error',
    APP_CONFIG_CACHE:path.relative(root,path.join(work,'config.php')), APP_ROUTES_CACHE:path.relative(root,path.join(work,'routes.php')),
    APP_EVENTS_CACHE:path.relative(root,path.join(work,'events.php')), FILAMENT_CACHE_PATH:path.join(work,'filament')};
if(args.profile)env.LOAD_TEST_METRICS=path.join(work,'php-metrics.jsonl');
for (const dir of ['cache','sessions']) fs.mkdirSync(path.join(work,dir),{recursive:true});
function artisan(...argv) {
    const result=spawnSync(php,['-d','opcache.enable_cli=0','artisan',...argv],{cwd:root,env,encoding:'utf8',windowsHide:true,timeout:1800000});
    if(result.status!==0) throw Error(result.stdout+'\n'+result.stderr);
    process.stdout.write(result.stdout);
}
const children=[]; let gateway;
const records=[]; const failures=[];
const clientAgent = new http.Agent({keepAlive:true,maxSockets:2000,maxFreeSockets:64});
const browserAgents=[];
const decode=s=>s.replace(/&quot;/g,'"').replace(/&#039;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
class Client {
    cookies = new Map();
    agent = new http.Agent({keepAlive:true,maxSockets:1,maxFreeSockets:1});
    constructor(){browserAgents.push(this.agent);}
    async request(url, options={}, label='other') {
        const started=performance.now();
        const headers={...options.headers, Cookie:[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ')};
        try {
            const response=await new Promise((resolve,reject)=>{
                const req=http.request(url.startsWith('http')?url:env.APP_URL+url,{method:options.method||'GET',headers,agent:this.agent},res=>{
                    let text='';res.setEncoding('utf8');res.on('data',chunk=>text+=chunk);res.on('end',()=>resolve({status:res.statusCode,text,headers:res.headers}));res.on('error',reject);
                });req.setTimeout(300000,()=>req.destroy(Error('Client deadline exceeded')));req.on('error',reject);req.end(options.body);
            });
            for(const cookie of response.headers['set-cookie']||[]) {const part=cookie.trim().split(';')[0]; const at=part.indexOf('='); if(at>0)this.cookies.set(part.slice(0,at),part.slice(at+1));}
            records.push({label,status:response.status,ms:performance.now()-started});
            return {status:response.status,text:response.text,location:response.headers.location};
        } catch(error) {records.push({label,status:'network_error',ms:performance.now()-started}); throw error;}
    }
    token(html) {const m=html.match(/name="_token"[^>]*value="([^"]+)"/) || html.match(/name="csrf-token"[^>]*content="([^"]+)"/); if(!m)throw Error('Missing CSRF token'); return decode(m[1]);}
    async form(url,data,token,label){return this.request(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:token,...data}).toString()},label);}
    async login(account,password) {
        const page=await this.request('/login',{},'staff_login_page');
        const snapshots=[...page.text.matchAll(/wire:snapshot="([^"]+)"/g)].map(m=>decode(m[1]));
        let snapshot=snapshots.find(s=>JSON.parse(s).memo.name.includes('login'));
        if(!snapshot)throw Error('Missing login snapshot');
        const token=this.token(page.text);
        const call=async(updates,calls)=>{
            const r=await this.request('/livewire/update',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':token,'X-Livewire':'true'},body:JSON.stringify({components:[{snapshot,updates,calls}]})},'staff_authenticate');
            if(r.status!==200)throw Error(`Staff Livewire status ${r.status}`);
            const component=JSON.parse(r.text).components[0]; snapshot=component.snapshot; return component;
        };
        await call({},[{path:'',method:'toggleStaffLogin',params:[]}]);
        const result=await call({'data.login':account.email,'data.password':password,'data.remember':false},[{path:'',method:'authenticate',params:[]}]);
        if(!result.effects.redirect) throw Error(`Staff login rejected for ${account.role}: ${JSON.stringify(result.effects).slice(0,180)}`);
    }
}
function summarize(items){const a=items.map(r=>r.ms).sort((a,b)=>a-b);const pct=p=>+(a[Math.min(a.length-1,Math.ceil(a.length*p)-1)]||0).toFixed(1);return {requests:a.length,p50_ms:pct(.5),p95_ms:pct(.95),p99_ms:pct(.99),max_ms:pct(1),statuses:items.reduce((o,r)=>(o[r.status]=(o[r.status]||0)+1,o),{})};}
async function mapLimit(items,limit,fn){let next=0;return Promise.all(Array.from({length:Math.min(limit,items.length)},async()=>{while(next<items.length){const i=next++;await fn(items[i],i);}}));}
async function waitPort(port){for(let i=0;i<150;i++){try{const r=await fetch(`http://127.0.0.1:${port}/up`);if(r.ok)return;}catch{}await new Promise(r=>setTimeout(r,200));}throw Error('Worker not ready '+port);}
async function main(){
    if(args.resume){if(!fs.existsSync(db))throw Error('No isolated database to resume');}
    else if(args.fixture){if(fs.existsSync(db))throw Error('Never overwrite an existing test database');fs.copyFileSync(path.resolve(args.fixture),db);fs.copyFileSync(path.join(path.dirname(path.resolve(args.fixture)),'fixture.json'),manifestPath);}
    else {if(fs.existsSync(db))throw Error('Use a new run directory');fs.closeSync(fs.openSync(db,'wx'));artisan('migrate','--force');artisan('classroom:evaluation-load-fixture',`--students=${studentCount}`,`--manifest=${manifestPath}`,...['faculty','subjects','section-size'].filter(k=>args[k]).map(k=>`--${k}=${args[k]}`));fs.copyFileSync(db,path.join(work,'fixture-pristine.sqlite'));}
    artisan('migrate','--force');
    if(args.configCache)artisan('config:cache');
    artisan('route:cache');
    artisan('event:cache');
    artisan('filament:cache-components');
    const fixture=JSON.parse(fs.readFileSync(manifestPath,'utf8'));
    // Same PHP settings as the campus launcher (desktop-host/resources/server/host.cjs), so results match what students get.
    const opcacheDir=path.join(work,'opcache');fs.mkdirSync(opcacheDir,{recursive:true});
    const opcache=['-d','opcache.enable=1','-d','opcache.enable_cli=1','-d','opcache.validate_timestamps=1','-d','opcache.revalidate_freq=2',
        '-d','opcache.memory_consumption=192','-d','opcache.max_accelerated_files=20000','-d',`opcache.file_cache=${opcacheDir}`,'-d','opcache.file_cache_fallback=1'];
    const opcacheLoaded=extra=>spawnSync(php,[...extra,'-r',"exit(extension_loaded('Zend OPcache') ? 0 : 1);"],{windowsHide:true,timeout:15000,stdio:'ignore'}).status===0;
    if(!opcacheLoaded([])){if(opcacheLoaded(['-d','zend_extension=opcache']))opcache.unshift('-d','zend_extension=opcache');else{opcache.length=0;process.stderr.write('Warning: OPcache is unavailable; results will be slower than the campus launcher.\n');}}
    for(let i=1;i<=workers;i++){
        const log=fs.openSync(path.join(work,`worker-${i}.log`),'a');
        const child=spawn(php,[...opcache,'-S',`127.0.0.1:${basePort+i}`,path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'public'),env,windowsHide:true,stdio:['ignore',log,log]});
        children.push(child); child.once('exit',code=>{if(code!==null&&code!==0)process.stderr.write(`Worker exited: ${code}\n`);});
    }
    await Promise.all(children.map((_,i)=>waitPort(basePort+i+1)));
    let roundRobin=0;
    gateway=http.createServer((req,res)=>{
        const upstream=http.request({host:'127.0.0.1',port:basePort+1+(roundRobin++%workers),path:req.url,method:req.method,headers:req.headers},r=>{const headers={...r.headers};delete headers.connection;delete headers['keep-alive'];delete headers['transfer-encoding'];res.writeHead(r.statusCode,headers);r.pipe(res);});
        upstream.setTimeout(300000,()=>upstream.destroy());
        upstream.on('error',()=>{if(!res.headersSent)res.writeHead(503);res.end('Test worker unavailable');}); req.pipe(upstream);
    });
    gateway.requestTimeout=300000;
    gateway.keepAliveTimeout=300000;gateway.headersTimeout=310000;
    await new Promise(resolve=>gateway.listen({port:basePort,host:'127.0.0.1',backlog:4096},resolve));
    const roleChecks=[],staffClients=new Map();
    if(!args.skipRoles){
        for(const account of fixture.staff){
            const client=new Client();
            try {
                await client.login(account,fixture.staff_password);
                staffClients.set(account.id,client);
                const home=await client.request(`/${fixture.campus}`,{},'role_dashboard');
                const other=await client.request(`/${fixture.foreign_campus}/evaluation-results`,{},'role_foreign_campus');
                const result={role:account.role,id:account.id,dashboard:home.status,foreign_campus:other.status,passed:home.status===200&&(account.role==='super_admin'?other.status===200:[403,404].includes(other.status))};
                roleChecks.push(result); process.stdout.write(JSON.stringify(result)+'\n');
            } catch(e){roleChecks.push({role:account.role,id:account.id,error:e.message,passed:false});}
        }
    }
    let succeeded=0,completed=0;
    let arrived=0,releaseBarrier;
    const barrier=new Promise(resolve=>releaseBarrier=resolve);
    const useBarrier=concurrency>=fixture.students.length;
    const arrive=()=>{if(++arrived===fixture.students.length)releaseBarrier();};
    const start=performance.now();
    process.stdout.write(`Starting ${fixture.students.length} virtual students, concurrency=${concurrency}, workers=${workers}\n`);
    await mapLimit(args.verifyOnly?[]:fixture.students,concurrency,async(student,index)=>{
        const client=new Client();
        let entered=false;
        try {
            if(args.rampMs)await new Promise(resolve=>setTimeout(resolve,Number(args.rampMs)*index/fixture.students.length));
            let r=await client.request('/evaluations/start',{},'student_entry');
            entered=true;arrive();if(useBarrier)await barrier;
            if(r.status!==200)throw Error(`entry ${r.status}`);
            r=await client.form('/evaluations/start',{student_id:student.student_id},client.token(r.text),'student_identify');
            if(r.status!==302)throw Error(`identify ${r.status}`);
            r=await client.request('/evaluations/start',{},'student_assignments');
            if(r.status!==200)throw Error(`assignments ${r.status}`);
            for(const assignment of student.assignments){
                const form=await client.request(`/evaluate/${assignment}`,{},'student_form');
                if(form.status!==200)throw Error(`form ${form.status}`);
                const names=[...form.text.matchAll(/name="fields\[([^\]]+)\]"/g)].map(m=>m[1]);
                if(!names.length)throw Error('No rating fields');
                const data={overall_comment:'Isolated reliability test response.'};
                for(const name of new Set(names))data[`fields[${name}]`]='4';
                // --thinkMs: a student spends between half and all of this time reading and answering each form.
                if(args.thinkMs)await new Promise(resolve=>setTimeout(resolve,Number(args.thinkMs)*(0.5+Math.random()/2)));
                const saved=await client.form(`/evaluate/${assignment}`,data,client.token(form.text),'student_submit');
                if(saved.status!==302 || !saved.location?.includes('/success'))throw Error(`submit ${saved.status} ${saved.location||''}`);
                // --browse: like a browser, open the thank-you page and go back to the list before the next teacher.
                if(args.browse){
                    const done=await client.request(saved.location,{},'student_success');
                    if(done.status!==200)throw Error(`success ${done.status}`);
                    const list=await client.request('/evaluations/start',{},'student_assignments');
                    if(list.status!==200)throw Error(`assignments ${list.status}`);
                }
            }
            succeeded++;
        }catch(e){if(!entered)arrive();failures.push({student:student.student_id,error:e.message,cause:e.cause?.message,code:e.code});}
        completed++; if(completed%100===0)process.stdout.write(`${completed} finished, ${succeeded} succeeded, ${failures.length} failed\n`);
    });
    const elapsed=(performance.now()-start)/1000;
    const studentRecords=records.filter(r=>['student_entry','student_identify','student_assignments','student_form','student_submit','student_success'].includes(r.label));
    if(args.verifyOnly || args.fullCycle){
        for(const account of fixture.staff.filter(a=>['dean','chairperson'].includes(a.role))){
            const client=staffClients.get(account.id);if(!client)continue;
            const deptIndex=fixture.departments.indexOf(account.department);
            const foreignDept=fixture.departments[(deptIndex+1)%fixture.departments.length];
            const blocked=await client.request(`/${fixture.campus}/departments/${foreignDept}/edit`,{},'head_foreign_department');
            roleChecks.push({role:account.role,check:'foreign_department_denied',status:blocked.status,passed:[403,404].includes(blocked.status)});
            const progress=await client.request(`/${fixture.campus}/student-evaluation-progress`,{},'head_student_progress');
            const foreignStudent=fixture.students.find(s=>s.department===foreignDept).student_id;
            roleChecks.push({role:account.role,check:'progress_scoped',status:progress.status,passed:progress.status===200&&!progress.text.includes(foreignStudent)});
            // Each head answers Annex B for every faculty member of their department.
            const tasks=fixture.supervisor_tasks?fixture.supervisor_tasks[account.department]:fixture.supervisor_assignments.slice(deptIndex*2,deptIndex*2+2);
            for(const id of tasks){
                const form=await client.request(`/evaluate/${id}`,{},'supervisor_form');
                const data={overall_comment:'Isolated supervisor reliability test.'};
                for(const [,name] of form.text.matchAll(/name="fields\[([^\]]+)\]"/g))data[`fields[${name}]`]='5';
                const saved=await client.form(`/evaluate/${id}`,data,client.token(form.text),'supervisor_submit');
                roleChecks.push({role:account.role,assignment:id,check:'annex_b_submitted',status:saved.status,passed:saved.status===302&&saved.location?.includes('/success')});
            }
        }
        const student=fixture.students[0],client=new Client();
        let entry=await client.request('/evaluations/start',{},'boundary_student_entry');
        await client.form('/evaluations/start',{student_id:student.student_id},client.token(entry.text),'boundary_student_identify');
        entry=await client.request('/evaluations/start',{},'boundary_student_entry');
        const foreignAssignment=fixture.students.find(s=>s.department!==student.department).assignments[0];
        const foreign=await client.request(`/evaluate/${foreignAssignment}`,{},'student_foreign_assignment');
        roleChecks.push({role:'student',check:'foreign_assignment_denied',status:foreign.status,passed:[403,404].includes(foreign.status)});
        const portal=await client.request(`/${fixture.campus}`,{},'student_staff_portal');
        roleChecks.push({role:'student',check:'evaluation_session_does_not_unlock_staff',status:portal.status,passed:[302,403,404].includes(portal.status)});
        const answers=Object.fromEntries(Array.from({length:15},(_,i)=>[`fields[criterion_${i+1}]`,'4']));
        const duplicates=await Promise.all(Array.from({length:20},async()=>{const duplicate=new Client();duplicate.cookies=new Map(client.cookies);return duplicate.form(`/evaluate/${student.assignments[0]}`,answers,client.token(entry.text),'duplicate_submission');}));
        roleChecks.push({check:'concurrent_duplicate_posts',passed:duplicates.every(r=>r.status===302&&!r.location?.includes('/success')),requests:duplicates.length});
        for(const account of fixture.staff.filter(a=>a.role==='instructor')){
            const client=staffClients.get(account.id),assignment=fixture.faculty_classes?.[account.id]??fixture.students.find(s=>s.department===account.department).assignments[Number(account.email.match(/faculty\.(\d+)/)?.[1]||1)-1];
            const hidden=await client.request(`/evaluation-reports/${assignment}`,{},'faculty_report_before_close');
            roleChecks.push({role:'instructor',check:'report_hidden_before_close',status:hidden.status,passed:hidden.status===403});
        }
        for(const account of fixture.staff.filter(a=>['campus_admin','dean','chairperson'].includes(a.role))){
            const results=await staffClients.get(account.id).request(`/${fixture.campus}/evaluation-results`,{},'staff_results_after_load');
            roleChecks.push({role:account.role,check:'results_render_after_load',status:results.status,passed:results.status===200&&results.text.includes('80.00')&&results.text.includes('100.00')});
        }
        // A failed data check is reported with the timings instead of discarding them.
        try{artisan('classroom:evaluation-load-audit',manifestPath,'--close');}catch(error){failures.push({phase:'data_audit',error:error.message.slice(0,2000)});}
        for(const account of fixture.staff.filter(a=>a.role==='instructor')){
            const client=staffClients.get(account.id),assignment=fixture.faculty_classes?.[account.id]??fixture.students.find(s=>s.department===account.department).assignments[Number(account.email.match(/faculty\.(\d+)/)?.[1]||1)-1];
            const released=await client.request(`/evaluation-reports/${assignment}`,{},'faculty_report_after_close');
            roleChecks.push({role:'instructor',check:'report_released_after_close',status:released.status,passed:released.status===200&&released.text.includes('80.00')});
        }
        const closed=await client.form(`/evaluate/${student.assignments[1]}`,answers,client.token(entry.text),'closed_cycle_submit');
        roleChecks.push({check:'closed_cycle_rejects_new_submission',status:closed.status,passed:[403,409].includes(closed.status)});
    }
    const report={label:args.label||path.basename(work),created_at:new Date().toISOString(),hardware:{cpu:os.cpus()[0].model,logical_cpus:os.cpus().length,ram_gb:+(os.totalmem()/2**30).toFixed(1),platform:os.platform()},
        workers,virtual_students:fixture.students.length,concurrency,ramp_ms:Number(args.rampMs||0),entry_barrier:useBarrier,node:process.version,elapsed_seconds:+elapsed.toFixed(2),succeeded_students:succeeded,failed_students:failures.length,
        student_requests_per_second:+(studentRecords.length/elapsed).toFixed(2),student_requests:summarize(studentRecords),overall:summarize(records),
        endpoints:Object.fromEntries([...new Set(records.map(r=>r.label))].map(label=>[label,summarize(records.filter(r=>r.label===label))])),role_checks:roleChecks,failures:failures.slice(0,100)};
    fs.writeFileSync(path.join(work,args.verifyOnly?'role-results.json':'http-results.json'),JSON.stringify(report,null,2));
    if(failures.length || roleChecks.some(check=>check.passed!==true))process.exitCode=1;
    process.stdout.write(JSON.stringify({succeeded,failed:failures.length,elapsed_seconds:report.elapsed_seconds,overall:report.overall})+'\n');
}
main().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>{clientAgent.destroy();for(const agent of browserAgents)agent.destroy();gateway?.closeAllConnections();gateway?.close();for(const child of children)child.kill();});
