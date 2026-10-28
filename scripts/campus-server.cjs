// Stable public gateway. Application releases run on independent private workers.
const {CampusServer}=require('../desktop-host/resources/server/campus-server.cjs');
const [project, php, port] = process.argv.slice(2);
const server=new CampusServer(project,php,port);
const stop=()=>server.stop().then(()=>process.exit(0)).catch(error=>{console.error(error.message);process.exitCode=1});
process.on('SIGINT',stop);process.on('SIGTERM',stop);
server.start().catch(async error=>{
    console.error(error.message);server.updater?.stop();
    for(const backend of server.gateway.backends.values())await backend.stop().catch(()=>{});
    server.status('error',error.message);process.exit(1);
});
