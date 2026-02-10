<script>
  let todayStr;

  function todayYMD() {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function titleKey(dateStr) {
    return 'wo_title_' + dateStr;
  }

  function showToast(msg) {
    $('#toast-body').text(msg);
    const el = document.getElementById('app-toast');
    const t = bootstrap.Toast.getOrCreateInstance(el, { delay: 1500 });
    t.show();
  }

  function loadTodayWorkout() {
    $('#tbl-ex tbody').empty();
    $('#msg-ex').addClass('d-none');

    const v = Date.now();
    $.getJSON('api.php?action=getWorkout&date=' + encodeURIComponent(todayStr))
      .done(function (res) {
        const items = res.items || [];
        const apiTitle = (res.title || '').trim();

        // se non ci sono record, il titolo "persistente" lo teniamo in localStorage
        const savedTitle = (localStorage.getItem(titleKey(todayStr)) || '').trim();
        const effectiveTitle = apiTitle || savedTitle;

        $('#txt-title').val(effectiveTitle);

        if (!items.length) {
          $('#msg-ex').removeClass('d-none alert-danger')
            .addClass('alert alert-info')
            .text('Workout vuoto: aggiungi un esercizio.');
          return;
        }

        items.forEach(function (it) {
          const tr = $('<tr>');

          const link = $('<a href="#">').text(it.activity).on('click', function (e) {
            e.preventDefault();
            window.location = 'esercizio.html?id='
              + encodeURIComponent(it.id) + '&date=' + encodeURIComponent(todayStr) + '&v=' + v;
          });
          tr.append($('<td>').append(link));

          let summary = '';
          if (it.pairs && it.pairs.length) {
            summary = it.pairs.map(p => p.reps + 'x' + p.weight).join(', ');
          }
          tr.append($('<td>').text(summary));

          const btnDel = $('<button>')
            .addClass('btn btn-sm btn-outline-danger')
            .text('Cancella')
            .on('click', function () {
              if (!confirm('Cancellare tutto l\'esercizio "' + it.activity + '"?')) return;

              $.post('api.php?action=deleteExercise', { id: it.id })
                .done(function (r) {
                  if (r.success) loadTodayWorkout();
                  else alert(r.error || 'Errore cancellazione');
                });
            });

          tr.append($('<td class="text-end">').append(btnDel));
          $('#tbl-ex tbody').append(tr);
        });
      });
  }

  $(function () {
    // senza parametri -> SEMPRE oggi (NO cloneWorkout)
    todayStr = todayYMD();
    $('#lbl-date').text(todayStr);

    loadTodayWorkout();

    $('#btn-save-title').on('click', function () {
      const t = ($('#txt-title').val() || '').trim();

      $.post('api.php?action=updateWorkoutTitle', { date: todayStr, title: t })
        .done(function (r) {
          if (r.success) {
            if ((r.updated | 0) > 0) {
              // esiste il giorno (ha record): titolo scritto sul DB
              localStorage.removeItem(titleKey(todayStr));
              showToast('Titolo salvato');
              loadTodayWorkout();
            } else {
              // giorno NON esiste (0 record): niente DB, memorizzo localmente
              localStorage.setItem(titleKey(todayStr), t);
              showToast('Titolo memorizzato (sarà applicato al primo esercizio)');
            }
          } else {
            alert(r.error || 'Errore salvataggio titolo');
          }
        })
        .fail(function () { alert('Errore salvataggio titolo'); });
    });

    $('#btn-save-ex').on('click', function () {
      const name = ($('#txt-activity').val() || '').trim();
      if (!name) {
        $('#msg-ex').removeClass('d-none alert-success')
          .addClass('alert alert-danger').text('Inserisci un nome esercizio.');
        return;
      }

      const title = ($('#txt-title').val() || '').trim();

      $.post('api.php?action=addExercise', {
        date: todayStr,
        title: title,
        activity: name
      }).done(function (r) {
        if (r.success) {
          $('#txt-activity').val('');
          // ora il giorno esiste sicuramente: posso togliere il title locale
          localStorage.removeItem(titleKey(todayStr));
          showToast('Esercizio salvato');
          loadTodayWorkout();
        } else {
          alert(r.error || 'Errore salvataggio esercizio');
        }
      });
    });
  });
</script>
