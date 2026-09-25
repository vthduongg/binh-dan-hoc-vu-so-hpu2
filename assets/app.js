document.addEventListener('DOMContentLoaded',()=>{
  const toggle=document.querySelector('[data-nav-toggle]');
  const nav=document.querySelector('[data-nav]');
  toggle?.addEventListener('click',()=>nav?.classList.toggle('open'));
  const search=document.querySelector('[data-course-search]');
  search?.addEventListener('input',()=>{
    const q=search.value.toLocaleLowerCase('vi').trim();
    document.querySelectorAll('[data-course-grid] .course-card').forEach(card=>{
      card.hidden=q!==''&&!card.textContent.toLocaleLowerCase('vi').includes(q);
    });
  });
  window.setTimeout(()=>document.querySelectorAll('.flash').forEach(el=>el.remove()),5000);
  document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{
    if(!window.confirm(form.dataset.confirm||'Tiếp tục?'))event.preventDefault();
  }));
  const video=document.querySelector('[data-video-lesson]');
  video?.querySelectorAll('[data-video-progress]').forEach(button=>button.addEventListener('click',async()=>{
    const body=new URLSearchParams({csrf:video.dataset.csrf,lesson_id:video.dataset.videoLesson,percent:button.dataset.videoProgress});
    button.disabled=true;
    try{
      const response=await fetch(video.dataset.videoEndpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},body});
      if(!response.ok)throw new Error('Không thể lưu tiến độ');
      const data=await response.json();
      video.querySelector('[data-video-status]').textContent=`Đã ghi nhận ${data.percent}%`;
      video.querySelectorAll('[data-video-progress]').forEach(item=>item.classList.toggle('is-done',Number(item.dataset.videoProgress)<=Number(data.percent)));
    }catch(error){window.alert('Chưa lưu được tiến độ video. Vui lòng thử lại.');}
    finally{button.disabled=false;}
  }));
});
