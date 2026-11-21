(() => {
  'use strict';
  const config = window.etchHubSortConfig;
  if (!config?.connectionsEndpoint) return;
  const { __, _n, sprintf } = window.wp.i18n;
  const el = (tag, cls = '', text) => { const n = document.createElement(tag); n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const button = (text, click, cls = 'btn') => { const n = el('button', cls, text); n.type = 'button'; n.addEventListener('click', click); return n; };
  const svg = (tag, attrs, parent) => { const n = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const [k,v] of Object.entries(attrs)) n.setAttribute(k,v); parent?.append(n); return n; };
  let active = null;
  const layouts = new Map();
  window.etchHubMountConnections = (host, pageId = 0, initialMode = pageId ? 'page' : 'site') => {
    active?.destroy();
    const root = el('section', 'etch-delta-map'); root.setAttribute('aria-label', __('Connections map', 'etch-content-hub-sort'));
    const graph = el('section', 'etch-delta-graph');
    const header = el('header', 'graph-head');
    const icon = el('span', 'graph-ico connection-icon'); icon.setAttribute('aria-hidden','true');
    const search = el('input'); search.type = 'search'; search.placeholder = __('Find…','etch-content-hub-sort'); search.setAttribute('aria-label',__('Find an item','etch-content-hub-sort')); search.dataset.graph = 'search';
    const find = el('label','site-find'); find.append(el('span','search-icon'),search);
    const hint = el('span','site-hint'); hint.dataset.graph='hint'; hint.hidden=true;
    const spacer = el('span','spacer');
    const headerAction=button('×',()=>{
      if(mode==='site') {
        search.value='';
        search.dispatchEvent(new Event('input',{bubbles:true}));
        search.focus();
      } else window.etchHubContentPanel.close();
    },'icon-btn');
    search.addEventListener('input',()=>{headerAction.disabled=mode==='site'&&!search.value;});
    header.append(button(__('Back to list','etch-content-hub-sort'),()=>window.etchHubContentPanel.openList(),'btn map-back-to-list'),icon,el('b','',__('Connections','etch-content-hub-sort')),spacer,hint,find,headerAction);
    const stage = el('div','graph-stage');
    const legend = el('footer','graph-legend');
    graph.append(header,stage,legend);
    const aside = el('aside','etch-delta-detail'); aside.setAttribute('aria-label',__('Map details','etch-content-hub-sort'));
    const tabs = el('div','intabs map-tabs'); tabs.setAttribute('role','tablist'); tabs.setAttribute('aria-label',__('Map views','etch-content-hub-sort'));
    const tabGroup=el('div','intab-group');
    const editorItemId=Number(window.etch?.navigation?.getActivePostId?.())||Number(new URL(location.href).searchParams.get('post_id'))||pageId;
    let mappedItemId = pageId;
    const pageTab=button(pageId===editorItemId?__('Current item','etch-content-hub-sort'):__('Selected item','etch-content-hub-sort'),()=>loadPage(mappedItemId),'intab');
    const siteTab=button(__('Site','etch-content-hub-sort'),()=>loadSite(),'intab');
    for (const tab of [pageTab,siteTab]) tab.setAttribute('role','tab');
    tabGroup.append(pageTab,siteTab); tabs.append(tabGroup);
    const details=el('div','etch-delta-detail-content'); aside.append(tabs,details); root.append(graph,aside); host.replaceChildren(root);
    let alive=true, mode=initialMode, data=null, siteData=null, engine=null, controller=null, sequence=0, siteName='', cacheKey='';
    const own={destroy(){if(!alive)return;alive=false;controller?.abort();destroyEngine();observer.disconnect();root.remove();if(active===own)active=null;}};active=own;
    const observer=new ResizeObserver(()=>{ if(mode==='page') drawPage(root); }); observer.observe(stage);
    const status=(text)=>{ stage.replaceChildren(el('p','v-hint',text)); };
    const destroyEngine=()=>{ if(engine){layouts.set(cacheKey,engine.layout());engine.destroy();engine=null;} };
    const fetchData=async(path,signal)=>{const response=await fetch(`${config.connectionsEndpoint}/${path}`,{credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce},signal});const value=await response.json();if(!response.ok)throw new Error(value.message||__('Could not load map.','etch-content-hub-sort'));return value;};
    function setMode(next){ mode=next;const actionLabel=mode==='site'?__('Clear search','etch-content-hub-sort'):__('Close map','etch-content-hub-sort');headerAction.setAttribute('aria-label',actionLabel);headerAction.title=actionLabel;headerAction.disabled=mode==='site'&&!search.value;pageTab.disabled=!mappedItemId;pageTab.setAttribute('aria-selected',String(mode==='page'));siteTab.setAttribute('aria-selected',String(mode==='site'));find.hidden=mode!=='site';hint.hidden=true;legend.hidden=mode==='site'; }
    function begin(next){controller?.abort();controller=new AbortController();destroyEngine();setMode(next);details.replaceChildren();status(__('Loading…','etch-content-hub-sort'));return {seq:++sequence,signal:controller.signal};}
    function row(label,value){const r=el('div','gd-row');r.append(el('span','',label),el('b','',String(value)));return r;}
    function titleCard(label,color,sub){const card=el('div','selcard gd'),t=el('div','gd-title'),dot=el('i','dot');dot.style.background=color;t.append(dot,el('b','',label));card.append(t);if(sub)card.append(el('div','gd-sub',sub));return card;}
    function actions(node,card){
      const wrap=el('div','gd-actions');
      if(mode==='site' && node.kind==='post') {
        const id=Number(String(node.id).replace(/^post:/,''));
        if(id) wrap.append(button(__('View connections','etch-content-hub-sort'),()=>loadPage(id),'btn view-connections'));
      }
      if(node.etch_edit_url){const go=button(__('Edit with Etch','etch-content-hub-sort'),async()=>{go.disabled=true;try{await window.etch.navigation.openPostAsync(Number(String(node.id).replace(/^post:/,'')));window.etchHubContentPanel.close();}catch(error){go.disabled=false;card.append(el('p','gd-hint',error.message));}},'btn primary');wrap.append(go);}
      if(node.edit_url||node.edit){const link=el('a','btn',__('Edit with WP','etch-content-hub-sort'));link.href=node.edit_url||node.edit;link.target='_blank';link.rel='noopener';wrap.append(link);}
      if(node.add){const link=el('a','btn',__('Add new','etch-content-hub-sort'));link.href=node.add;link.target='_blank';link.rel='noopener';wrap.append(link);}
      if(node.view_url||node.view){const link=el('a','btn',__('View on site','etch-content-hub-sort'));link.href=node.view_url||node.view;link.target='_blank';link.rel='noopener';wrap.append(link);}
      return wrap;
    }
    function pageSummary(){
      details.replaceChildren(el('div','section-label',__('Connections','etch-content-hub-sort')));
      const subjectLabel=data.center.id===editorItemId?__('Current item','etch-content-hub-sort'):__('Selected item','etch-content-hub-sort');
      const card=titleCard(data.center.title,'var(--delta-ok)',sprintf(__('%1$s · ID %2$d','etch-content-hub-sort'),subjectLabel,data.center.id));
      card.append(row(__('Content type','etch-content-hub-sort'),data.center.type),row(__('Path','etch-content-hub-sort'),data.center.path||__('No public URL','etch-content-hub-sort')),row(__('Status','etch-content-hub-sort'),config.statusLabels?.[data.center.status]||data.center.status));
      card.append(row(__('Linked from','etch-content-hub-sort'),sprintf(_n('%d item','%d items',data.from.length||0,'etch-content-hub-sort'),data.from.length)),row(__('Links to','etch-content-hub-sort'),sprintf(_n('%d item','%d items',data.to.length||0,'etch-content-hub-sort'),data.to.length)),row(__('Uses','etch-content-hub-sort'),sprintf(_n('%d shared part','%d shared parts',data.uses.length||0,'etch-content-hub-sort'),data.uses.length)));
      if(data.used_by?.length || data.center.kind==='shared') card.append(row(__('Used by','etch-content-hub-sort'),sprintf(_n('%d item','%d items',data.used_by?.length||0,'etch-content-hub-sort'),data.used_by?.length||0)));
      details.append(card,actions(data.center,card),el('p','gd-hint',__('Click a card in the graph to see its details. Esc or the connections icon returns to the editor.','etch-content-hub-sort')));
      if(data.truncated)details.append(el('p','gd-hint',__('This map is limited to 500 incoming connections and 100 nested shared resources. Some relationships are omitted.','etch-content-hub-sort')));
      for(const node of root.querySelectorAll('.gnode')) node.setAttribute('aria-pressed','false');
    }
    function pageSelect(node,relation,selected){
      if(node.id===data.center.id){pageSummary();return;}
      for(const card of root.querySelectorAll('.gnode'))card.setAttribute('aria-pressed',String(card===selected));
      const head=el('div','section-label has-action');head.append(el('span','',__('Selected','etch-content-hub-sort')),button(__('Clear','etch-content-hub-sort'),pageSummary,'btn clear'));
      const card=titleCard(node.title,node.kind==='shared'?'var(--delta-shared)':'var(--delta-muted)');
      card.append(row(__('Type','etch-content-hub-sort'),node.type),row(__('Path','etch-content-hub-sort'),node.path||__('No public URL','etch-content-hub-sort')),row(__('Status','etch-content-hub-sort'),config.statusLabels?.[node.status]||node.status),row(__('Connection','etch-content-hub-sort'),relation));
      details.replaceChildren(head,card,actions(node,card));
    }
    function pageCard(node,relation,here=false){const n=button('',()=>pageSelect(node,relation,n),`gnode ${here?'here':node.kind==='shared'?'shared':'page'}`);n.append(el('b','',node.title),el('span','',here?sprintf(node.id===editorItemId?__('Current item · ID %d','etch-content-hub-sort'):__('Selected item · ID %d','etch-content-hub-sort'),node.id):node.kind==='shared'?node.type:node.path||node.type));n.setAttribute('aria-label',node.title);n.title=__('Show details','etch-content-hub-sort');n.setAttribute('aria-pressed','false');return n;}
    function renderPage(){
      stage.className='graph-stage';stage.removeAttribute('data-graph');
      const canvas=el('div','graph-canvas');svg('svg',{class:'graph-lines','aria-hidden':'true'},canvas);
      const grid=el('div','graph-grid');
      const group=(key,label,classes)=>{const col=el('div',`gcol ${classes}`),title=el('div','gcol-title',label+' ');title.append(el('span','count',data[key].length));col.append(title);for(const n of data[key])col.append(pageCard(n,label));if(!data[key].length)col.append(el('div','gempty',__('None','etch-content-hub-sort')));return col;};
      const center=el('div','gcol center');center.append(pageCard(data.center,'',true));const incoming=group('from',__('Linked from','etch-content-hub-sort'),'from');
      if(data.used_by?.length || data.center.kind==='shared') {
        const heading=el('div','gcol-title gcol-used-by-title',__('Used by','etch-content-hub-sort')+' ');heading.append(el('span','count',data.used_by?.length||0));incoming.append(heading);
        for(const node of data.used_by||[])incoming.append(pageCard(node,__('Used by','etch-content-hub-sort')));
        if(!data.used_by?.length)incoming.append(el('div','gempty',__('None','etch-content-hub-sort')));
      }
      grid.append(incoming,center,group('to',__('Links to','etch-content-hub-sort'),'to'));canvas.append(grid,group('uses',__('Uses','etch-content-hub-sort'),'uses row'));stage.replaceChildren(canvas);
      legend.replaceChildren();for(const [cls,text]of [['here',__('Current item','etch-content-hub-sort')],['page',__('Content item','etch-content-hub-sort')],['shared',__('Shared (template, component)','etch-content-hub-sort')]]){const item=el('span');item.append(el('i',`dot ${cls}`),document.createTextNode(text));legend.append(item);}
      pageSummary();requestAnimationFrame(()=>{if(!alive||mode!=='page')return;drawPage(root);stage.scrollLeft=(stage.scrollWidth-stage.clientWidth)/2;});
    }
    async function loadPage(id){
      if(!id)return;
      mappedItemId=id;
      pageTab.textContent=id===editorItemId?__('Current item','etch-content-hub-sort'):__('Selected item','etch-content-hub-sort');
      const request=begin('page');
      try{while(true){data=await fetchData(id,request.signal);if(!alive||request.seq!==sequence)return;if(!data.indexing)break;status(__('Building the link index…','etch-content-hub-sort'));}
      renderPage();}catch(error){if(error.name!=='AbortError'&&request.seq===sequence)status(error.message);}
    }
    function siteSummary(){
      details.replaceChildren(el('div','section-label',__('Site','etch-content-hub-sort')));const card=titleCard(siteName,'var(--delta-ok)',__('On the map','etch-content-hub-sort'));
      for(const [label,kind]of [[__('Post types','etch-content-hub-sort'),'type'],[__('Entries','etch-content-hub-sort'),'post'],[__('Terms','etch-content-hub-sort'),'term']])card.append(row(label,siteData.nodes.filter(n=>n.kind===kind).length));card.append(row(__('Links in content','etch-content-hub-sort'),siteData.edges.filter(e=>e.kind==='link').length));
      details.append(card,el('p','gd-hint',__('Shows public content types. Find nonpublic items and shared resources in List.','etch-content-hub-sort')),el('p','gd-hint',__('The ringed dot is the editor item. Click a dot to see its details. Scroll to zoom, drag to move. Esc returns to the editor.','etch-content-hub-sort')));
    }
    function siteSelected(node){
      if(!node){siteSummary();return;}const head=el('div','section-label has-action');head.append(el('span','',__('Selected','etch-content-hub-sort')),button(__('Clear','etch-content-hub-sort'),()=>engine.select(null),'btn clear'));
      const card=titleCard(node.label,node.color,node.id===`post:${editorItemId}`?__('Current item','etch-content-hub-sort'):node.kindName);
      if(node.description)card.append(el('p','gd-desc',node.description));
      for(const [key,value]of node.meta)card.append(row(key,value));details.replaceChildren(head,card);
      const source=siteData.nodes.find(n=>n.id===node.id);details.append(actions(source||node,card));
      for(const group of node.groups){const section=el('section','gd-rel');section.append(el('h4','',`${group.title} ${group.items.length}`));const list=el('ul');list.setAttribute('role','list');for(const item of group.items){const li=el('li'),go=button('',()=>engine.select(item.id,true),'');const dot=el('i',`dot${item.square?' square':''}`);dot.style.background=item.color;go.append(dot,document.createTextNode(item.label));go.addEventListener('mouseenter',()=>engine.hover(item.id));go.addEventListener('mouseleave',()=>engine.hover(null));li.append(go);list.append(li);}section.append(list);details.append(section);}
      if(!node.groups.length)details.append(el('p','gd-hint',__('No connections.','etch-content-hub-sort')));
    }
    function renderSite(){
      stage.className='delta-graph-stage site-stage';stage.dataset.graph='stage';stage.replaceChildren();
      const graphic=svg('svg',{class:'delta-graph-svg','data-graph':'svg',role:'img','aria-label':__('Site map','etch-content-hub-sort')},stage),view=svg('g',{'data-graph':'view'},graphic);
      for(const layer of ['edges','nodes','map','agent']){const g=svg('g',{'data-graph':layer},view);if(['map','agent'].includes(layer))g.style.display='none';}
      const tools=el('div','delta-graph-tools');for(const [key,label,text]of [['zoom-in',__('Zoom in','etch-content-hub-sort'),'+'],['zoom-out',__('Zoom out','etch-content-hub-sort'),'−'],['fit',__('Fit','etch-content-hub-sort'),__('Fit','etch-content-hub-sort')]]){const b=button(text,()=>{},`delta-graph-tool${key==='fit'?' delta-graph-tool--wide':''}`);b.dataset.graph=key;b.setAttribute('aria-label',label);tools.append(b);}stage.append(tools);
      for(const part of ['filters','legend']){const block=el('div',`delta-graph-${part}`);block.dataset.graph=part;block.setAttribute('role','group');block.setAttribute('aria-label',part==='filters'?__('Show or hide groups','etch-content-hub-sort'):__('Graph display','etch-content-hub-sort'));stage.append(block);}
      cacheKey=location.origin+':'+siteData.nodes.map(n=>n.id).join(',');
      engine=window.etchHubDeltaGraph(root,siteData,null,'graph',{here:`post:${editorItemId}`,layout:layouts.get(cacheKey),fitOnOpen:true,keys:false,onSelect:siteSelected});siteSummary();
    }
    async function loadSite(){
      const request=begin('site');if(siteData){renderSite();return;}
      try{const posts=[],edges=[],termEdges=[];let after=0,schema={};
        while(true){const batch=await fetchData(`site?after=${after}`,request.signal);if(!alive||request.seq!==sequence)return;if(batch.indexing){status(__('Building the link index…','etch-content-hub-sort'));continue;}posts.push(...batch.nodes);edges.push(...batch.edges);termEdges.push(...(batch.term_edges||[]));schema=batch.taxonomies||{};siteName=batch.site_name||document.title;if(batch.done)break;if(batch.next_cursor<=after)throw new Error(__('Site map scan could not advance.','etch-content-hub-sort'));after=batch.next_cursor;}
        const terms=[];after=0;while(true){const batch=await fetchData(`terms?after=${after}`,request.signal);if(!alive||request.seq!==sequence)return;terms.push(...batch.nodes);if(batch.done)break;if(batch.next_cursor<=after)throw new Error(__('Term scan could not advance.','etch-content-hub-sort'));after=batch.next_cursor;}
        const nodes=[],out=[],visible=posts.filter(n=>n.public),ids=new Set(visible.map(n=>n.id));
        const groups=new Map();for(const node of visible){if(!groups.has(node.post_type))groups.set(node.post_type,[]);groups.get(node.post_type).push(node);}
        for(const [type,items]of groups){nodes.push({id:`type:${type}`,kind:'type',group:type,label:items[0].type_label,total:items.length,loaded:items.length,meta:{Published:items.filter(n=>n.status==='publish').length,Drafts:items.filter(n=>['draft','pending'].includes(n.status)).length,Slug:type},edit:items[0].type_manage_url,view:items[0].type_archive_url,add:items[0].type_add_url});for(const n of items){nodes.push({...n,id:`post:${n.id}`,kind:'post',group:type,label:n.title,meta:{Type:n.type,Status:n.status,Modified:n.modified},edit:n.edit_url,view:n.view_url});out.push({source:n.parent&&ids.has(n.parent)?`post:${n.id}`:`type:${type}`,target:`post:${n.parent&&ids.has(n.parent)?n.parent:n.id}`,kind:n.parent&&ids.has(n.parent)?'parent':'has'});}}
        for(const [name,tax]of Object.entries(schema)){const members=terms.filter(n=>n.group===name);nodes.push({id:`tax:${name}`,kind:'tax',group:name,label:tax.label,total:members.length,loaded:members.length,meta:{Terms:members.length,Slug:name},edit:tax.edit});for(const type of tax.types)if(groups.has(type))out.push({source:`type:${type}`,target:`tax:${name}`,kind:'uses'});for(const term of members){nodes.push(term);out.push({source:`tax:${name}`,target:term.id,kind:'of'});if(term.parent)out.push({source:term.id,target:term.parent,kind:'parent'});}}
        const allIds=new Set(nodes.map(n=>n.id));out.push(...termEdges.filter(e=>allIds.has(e.source)&&allIds.has(e.target)));for(const e of edges)if(e.kind==='link'&&ids.has(e.from)&&ids.has(e.to))out.push({source:`post:${e.from}`,target:`post:${e.to}`,kind:'link'});
        siteData={nodes,edges:out,site:siteName};renderSite();
      }catch(error){if(error.name!=='AbortError'&&request.seq===sequence)status(error.message);}
    }
    // Delta's Escape behavior returns from the map to the editor.
    root.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();window.etchHubContentPanel.close();}});
    if(initialMode==='page'&&pageId)loadPage(pageId);else loadSite();
  };
function gt(m){const d=m.querySelector(".graph-canvas"),h=m.querySelector(".graph-lines"),l=m.querySelector(".gnode.here");if(!d||!h||!l)return;const p=d.getBoundingClientRect(),v=10,x=E=>({l:E.left-p.left,r:E.right-p.left,t:E.top-p.top,b:E.bottom-p.top,cx:E.left+E.width/2-p.left,cy:E.top+E.height/2-p.top}),k=x(l.getBoundingClientRect()),b=E=>[...m.querySelectorAll(E)].map(C=>x(C.getBoundingClientRect()));let w="";const q=(E,C)=>{w+=`<path class="${C}" d="${E}"/>`},M=(E,C)=>{if(!E.length)return;const $=se=>C<0?se.r:se.l,z=C<0?k.l:k.r,P=(z+$(E[0]))/2,D=E.map(se=>se.cy),J=Math.min(...D),G=Math.max(...D),te=Math.min(v,(G-J)/2);if(q(C<0?`M${P} ${k.cy}H${z}`:`M${z} ${k.cy}H${P}`,C<0?"link arrow":"link"),E.length===1){q(C<0?`M${$(E[0])} ${J}H${P}V${k.cy}`:`M${P} ${k.cy}V${J}H${$(E[0])}`,C<0?"link":"link arrow");return}q(`M${P} ${Math.min(J+te,k.cy)}V${Math.max(G-te,k.cy)}`,"link"),E.forEach(se=>{const Q=se.cy,me=Q===J?`M${P} ${Q+te}Q${P} ${Q} ${P+te*C} ${Q}`:Q===G?`M${P} ${Q-te}Q${P} ${Q} ${P+te*C} ${Q}`:`M${P} ${Q}`;q(`${me}H${$(se)}`,C<0?"link":"link arrow")})};M(b(".gcol.from .gnode"),-1),M(b(".gcol.to .gnode"),1);const W=b(".gcol.uses .gnode");if(W.length){const E=(k.b+Math.min(...W.map(D=>D.t)))/2-10,C=W.map(D=>D.cx),$=Math.min(...C),z=Math.max(...C),P=Math.min(v,(z-$)/2);q(`M${k.cx} ${k.b}V${E}`,"shared"),W.length>1&&q(`M${$+P} ${E}H${z-P}`,"shared"),W.forEach(D=>{const J=W.length>1&&D.cx===$?`M${$+P} ${E}Q${$} ${E} ${$} ${E+P}`:W.length>1&&D.cx===z?`M${z-P} ${E}Q${z} ${E} ${z} ${E+P}`:`M${D.cx} ${E}`;q(`${J}V${D.t}`,"shared")})}h.setAttribute("viewBox",`0 0 ${p.width} ${p.height}`),h.innerHTML='<defs><marker id="ah" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L8 4L0 8z" fill="currentColor"/></marker></defs>'+w}  function drawPage(root){gt(root);}
  window.etchHubCloseConnections=()=>active?.destroy();
  window.etchHubShowConnections=id=>window.etchHubContentPanel?.openMap(id);
})();
