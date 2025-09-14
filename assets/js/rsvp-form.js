(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var findBtn = document.getElementById('wprsvp-find');
    var initialForm = document.getElementById('wprsvp-initial');
    var fullForm = document.getElementById('wprsvp-full');
    var msgDiv = document.getElementById('wprsvp-message');

    findBtn.addEventListener('click', function(e){
      e.preventDefault();
      var first = document.getElementById('wprsvp-first').value.trim();
      var last  = document.getElementById('wprsvp-last').value.trim();
      if (!first || !last){ alert('Please enter both first and last name'); return; }
      var fd = new FormData();
      fd.append('action','wprsvp_search');
      fd.append('nonce', wprsvp_vars.nonce);
      fd.append('first_name', first);
      fd.append('last_name', last);

      fetch(wprsvp_vars.ajax_url, { method: 'POST', body: fd }).then(function(res){ return res.json(); }).then(function(data){
        if (!data.success){ alert('Server error'); return; }
        if (data.data.found){
          var g = data.data.guest;
          document.getElementById('wprsvp-id').value = g.id;
          document.getElementById('wprsvp-email').value = g.email || '';
          document.getElementById('wprsvp-meal').value = g.guest_meal || '';
          document.getElementById('wprsvp-rsvp').value = g.rsvp_status || '';

          if (data.data.partner){
            var p = data.data.partner;
            document.getElementById('wprsvp-partner-block').style.display = 'block';
            document.getElementById('wprsvp-partner-note').innerText = 'Partner: ' + p.first_name + ' ' + p.last_name;
            var existing = document.getElementById('wprsvp-partner-id');
            if (!existing){
              var inp = document.createElement('input');
              inp.type='hidden'; inp.id='wprsvp-partner-id'; inp.name='partner_id'; inp.value = p.id;
              fullForm.appendChild(inp);
            } else { existing.value = p.id; }
            document.getElementById('wprsvp-partner-rsvp').value = p.rsvp_status || '';
            document.getElementById('wprsvp-partner-meal').value = p.guest_meal || '';
          } else {
            document.getElementById('wprsvp-partner-block').style.display = 'none';
            var existing = document.getElementById('wprsvp-partner-id'); if (existing) existing.remove();
            document.getElementById('wprsvp-partner-note').innerText='';
            document.getElementById('wprsvp-partner-rsvp').value='';
            document.getElementById('wprsvp-partner-meal').value='';
          }

        } else {
          document.getElementById('wprsvp-id').value = '';
          document.getElementById('wprsvp-email').value = '';
          document.getElementById('wprsvp-meal').value = '';
          document.getElementById('wprsvp-rsvp').value = '';
          document.getElementById('wprsvp-partner-block').style.display = 'none';
          var existing = document.getElementById('wprsvp-partner-id'); if (existing) existing.remove();
        }
        fullForm.style.display = 'block';
      }).catch(function(err){ console.error(err); alert('Network error'); });
    });

    fullForm.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(fullForm);
      fd.append('first_name', document.getElementById('wprsvp-first').value.trim());
      fd.append('last_name', document.getElementById('wprsvp-last').value.trim());
      fd.append('action','wprsvp_submit');
      fd.append('nonce', wprsvp_vars.nonce);

      fetch(wprsvp_vars.ajax_url, { method:'POST', body: fd }).then(function(res){ return res.json(); }).then(function(data){
        if (!data.success){
          msgDiv.style.display = 'block';
          msgDiv.innerText = (data.data && data.data.message) ? data.data.message : 'Error saving RSVP';
          msgDiv.style.color = 'red';
        } else {
          msgDiv.style.display = 'block';
          msgDiv.innerText = (data.data && data.data.message) ? data.data.message : 'Saved';
          msgDiv.style.color = 'green';
          fullForm.reset();
          initialForm.reset();
          fullForm.style.display = 'none';
        }
      }).catch(function(err){ console.error(err); alert('Network error'); });

    });

  });
})();