import { initModule } from './moduleInit.js';
import DataTable from 'datatables.net';

export function init(cfg) {
  const { nbElements, rootUrl, titleMsg, confirmMsg, cancelMsg } = cfg;

  const h1 = document.querySelector('h1');
  if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbElements + "</span>");

  const oTable = new DataTable('#rateTable', {
    dom: '<"dtBar"filp>rt<"dtBar"ilp>',
    pageLength: 100,
    lengthMenu: [
      [25, 50, 100, 500, -1],
      [25, 50, 100, 500, "All"]
    ],
    order: [],
    autoWidth: false,
    columnDefs: [{
        targets: ["dtc_user"],
        type: "string"
      },
      {
        targets: ["dtc_date"],
        orderSequence: ["desc", "asc"],
        type: "string"
      },
      {
        targets: ["dtc_stat"],
        orderSequence: ["desc", "asc"],
        searchable: false,
        type: "numeric"
      },
      {
        targets: ["dtc_rate"],
        orderSequence: ["desc", "asc"],
        searchable: false,
        type: "html"
      },
      {
        targets: ["dtc_del"],
        orderable: false,
        searchable: false,
        type: "string"
      }
    ]
  });

  function uidFromCell(cell) {
    let tr = cell;
    while (tr.nodeName !== "TR") tr = tr.parentNode;
    return JSON.parse(tr.dataset.usr);
  }

  const rateTable = document.getElementById("rateTable");
  if (rateTable) {
    rateTable.addEventListener("click", function(e) {
      const delBtn = e.target.closest(".del");
      if (!delBtn) return;
      e.preventDefault();
      let trEl = delBtn.closest("tr");
      const usr_name = trEl ? (trEl.querySelector(".usr")?.innerHTML ?? '') : '';

      pwgConfirm({
        title: titleMsg.replace("%s", usr_name),
        buttons: {
          confirm: {
            text: confirmMsg,
            btnClass: 'btn-red',
            action: function() {
              const cell = e.target.parentNode;
              let trEl = cell;
              while (trEl.nodeName !== "TR") trEl = trEl.parentNode;
              const anim = trEl.animate([{ opacity: 1 }, { opacity: 0.4 }], { duration: 1000, fill: 'forwards' });
              const data = uidFromCell(cell);
              (new PwgWS(rootUrl)).callService(
                'pwg.rates.delete', {
                  user_id: data.uid,
                  anonymous_id: data.aid
                }, {
                  method: 'POST',
                  onFailure: function(num, text) {
                    anim.cancel();
                    trEl.style.opacity = '1';
                    alert(num + " " + text);
                  },
                  onSuccess: function(result) {
                    if (result)
                      oTable.row(trEl).remove().draw();
                    else
                      alert(result);
                  }
                }
              );
            }
          },
          cancel: {
            text: cancelMsg
          }
        }
      });
    });
  }
}

initModule(init);
