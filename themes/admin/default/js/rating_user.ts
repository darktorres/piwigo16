export {};

// GeoIp -- themes/admin/default/js/jquery.geoip.js, loaded via the
// same page's own combineScript() call (excluded from P46's own
// 61-file count, not converted yet -- ambient `declare const GeoIp`
// in build/jquery-plugins.d.ts stands in for it in the meantime).

$(document).ready(function () {
  $("h1").append(
    "<span class='badge-number'>" + pwg_getPageData("nb_elements") + "</span>",
  );
});

const pwg_token = pwg_getPageData("csrf_token");

jQuery("#rateTable").dataTable({
  dom: '<"dtBar"filp>rt<"dtBar"ilp>',
  pageLength: 100,
  lengthMenu: [
    [25, 50, 100, 500, -1],
    [25, 50, 100, 500, "All"],
  ],
  sorting: [], //[[1,'desc']],
  autoWidth: false,
  sortClasses: false,
  columnDefs: [
    {
      aTargets: ["dtc_user"],
      sType: "string",
      sClass: null,
    },
    {
      aTargets: ["dtc_date"],
      asSorting: ["desc", "asc"],
      sType: "string",
      sClass: null,
    },
    {
      aTargets: ["dtc_stat"],
      asSorting: ["desc", "asc"],
      bSearchable: false,
      sType: "numeric",
      sClass: null,
    },
    {
      aTargets: ["dtc_rate"],
      asSorting: ["desc", "asc"],
      bSearchable: false,
      sType: "html",
      sClass: null,
    },
    {
      aTargets: ["dtc_del"],
      bSortable: false,
      bSearchable: false,
      sType: "string",
      sClass: null,
    },
  ],
});

const oTable = jQuery("#rateTable").DataTable();

function uidFromCell(cell: any) {
  let tr = cell;
  while (tr.nodeName !== "TR") tr = tr.parentNode;
  return $(tr).data("usr");
}

// -----DELETE-----
$(document).ready(function () {
  $("#rateTable").on("click", ".del", function (e) {
    e.preventDefault();
    const title_msg = pwg_getPageString(
      'Are you sure you want to delete the ratings of the user "%s"?',
    );
    const confirm_msg = pwg_getPageString("Yes, I am sure");
    const cancel_msg = pwg_getPageString("No, I have changed my mind");
    const usr_name = $(this).closest("tr").find(".usr").html();
    $.confirm({
      title: title_msg.replace("%s", usr_name),
      buttons: {
        confirm: {
          text: confirm_msg,
          btnClass: "btn-red",
          action: function () {
            const cell: any = e.target.parentNode;
            let tr = cell;
            while (tr.nodeName !== "TR") tr = tr.parentNode;
            tr = jQuery(tr).fadeTo(1000, 0.4);
            const data = uidFromCell(cell);
            $.ajax({
              url:
                pwg_getPageData("root_url") +
                "api/v1/users/" +
                data.uid +
                "/actions/delete-ratings",
              method: "POST",
              contentType: "application/json",
              data: JSON.stringify({ anonymousId: data.aid || null }),
              headers: { "X-CSRF-Token": pwg_token },
              error: function (jqXHR: any) {
                tr.stop();
                tr.fadeTo(0, 1);
                alert(jqXHR.status + " " + jqXHR.statusText);
              },
              success: function (result: any) {
                if (result.deletedCount) oTable.row(tr[0]).remove().draw();
                else alert(result.deletedCount);
              },
            });
          },
        },
        cancel: {
          text: cancel_msg,
        },
      },
      ...jConfirm_confirm_options,
    });
  });
});

jQuery(document).ready(function () {
  jQuery("#rateTable").tooltip({
    items: ".usr,[title]",
    content: function (this: any, callback: (content: string) => void) {
      const t = $(this).attr("title");
      if (t) return t;
      const that = $(this),
        udata = uidFromCell(this);
      if (!udata.aid) return;
      that.data("isOver", true).one("mouseleave", function () {
        that.removeData("isOver");
      });

      GeoIp.get(udata.aid + ".1", function (data: any) {
        if (!data.fullName) return;
        let content = data.fullName;
        if (data.latitude && data.region_name) {
          content +=
            "<" +
            "br>" +
            "<" +
            'img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap?sensor=false&size=300x220&zoom=6' +
            "&markers=size:tiny%7C" +
            data.latitude +
            "," +
            data.longitude +
            '">';
        }
        if (that.data("isOver")) callback(content);
      });
    },
  });
});
