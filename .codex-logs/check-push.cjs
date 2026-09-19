const expression = `(async () => {
 const Push = window.Capacitor?.Plugins?.PushNotifications;
 let permission; try { permission = Push ? await Push.checkPermissions() : null; } catch(e) { permission = {error:String(e?.message || e)}; }
 const source = Array.from(document.scripts).map(s=>s.textContent).join(' ');
 const match = source.match(/var MALL_PUSH_CSRF_TOKEN = ("[^"]+"|null);/);
 if (!match || match[1] === 'null') return {permission, error:'no_authenticated_csrf'};
 let response;
 try { response = await fetch('/mall/ajax/diagnose_fcm.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:JSON.parse(match[1])})}); }
 catch(e) { return {permission,online:navigator.onLine,origin:location.origin,error:String(e.message)}; }
 let diagnostic; try { diagnostic=await response.json(); } catch {diagnostic={error:'non_json_response'};}
 return {permission,http:response.status,diagnostic};
})()`;
(async()=>{
 const pages=await (await fetch('http://127.0.0.1:9229/json')).json();
 const ws=new WebSocket(pages[0].webSocketDebuggerUrl);
 const timeout=setTimeout(()=>{ws.close();process.exitCode=1;},30000);
 ws.onopen=()=>{ws.send(JSON.stringify({id:2,method:'Network.enable'}));ws.send(JSON.stringify({id:1,method:'Runtime.evaluate',params:{expression,awaitPromise:true,returnByValue:true}}));};
 ws.onmessage=e=>{const r=JSON.parse(e.data);if(r.method==='Network.loadingFailed') console.log(JSON.stringify({networkError:r.params.errorText,blockedReason:r.params.blockedReason}));if(r.id===1){console.log(JSON.stringify({value:r.result?.result?.value,exception:r.result?.exceptionDetails?.text,description:r.result?.result?.description}));clearTimeout(timeout);ws.close();}};
})();
