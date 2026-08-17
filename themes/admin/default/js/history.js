$(document).ready(() => {

  activateLineOptions();
  checkFilters();

  if (current_param.ip != "") {
    addIpFilter(current_param.ip);
  }
  if (current_param.image_id != "") {
    addImageFilter(current_param.image_id);
  }
  if (current_param.user_id != "-1") {
    addUserFilter(filter_user_name);
  }

  $(".elem-type-select").on("change", function (e) {
    console.log($(".elem-type-select option:selected").attr("value"));

    if ($(".elem-type-select option:selected").attr("value") == "visited") {
      current_param.types = {
        0: "none",
        1: "picture"
      }
    } else if ($(".elem-type-select option:selected").attr("value") == "downloaded"){
      current_param.types = {
        0: "high",
        1: "other"
      }
    } else {
      current_param.types = {
        0: "none",
        1: "picture",
        2: "high",
        3: "other"
      }
    }

    fillHistoryResult(current_param)
  });

  $('.date-start').on("change", function () {
    if (current_param.start != $('.date-start input[name="start"]').attr("value")) {
      current_param.start = $('.date-start input[name="start"]').attr("value");
      current_param.pageNumber = 0;
      fillHistoryResult(current_param);
    }
  });

  $('.date-end').on("change", function () {
    const newValue = $('.date-end input[name="end"]').attr("value");
    if (current_param.end != newValue) {
      current_param.end = $('.date-end input[name="end"]').attr("value");
      current_param.pageNumber = 0;
      // The datepicker first fills the end-date with '1899-12-31',
      // which triggers an unnecessary ajax request
      // when you come to the history search page from a photo.
      if (newValue !== '1899-12-31') {
        fillHistoryResult(current_param);
      }
    }
  });

  $("#start_unset").on("click", function () {
    console.log("here" + current_param.start);
    if (!current_param.start == "") {
      
      current_param.pageNumber = 0;
      current_param.start = "";
      fillHistoryResult(current_param);
    }
  });

  $("#end_unset").on("click", function () {
    if (!current_param.start == today) {
      current_param.end = today;
      current_param.pageNumber = 0;
      fillHistoryResult(current_param);
    }
  });


  $('.pagination-arrow.rigth').on('click', () => {
    current_param.pageNumber += 1;
    fillHistoryResult(current_param);
  });
  
  $('.pagination-arrow.left').on('click', () => {
    current_param.pageNumber -= 1;
    fillHistoryResult(current_param);
  });

  $(".refresh-results").on("click", function () {
    fillHistoryResult(current_param);
  })
})

function activateLineOptions() {
  $(".search-line").find(".img-option").hide();

  /* Display the option on the click on "..." */
  $(".search-line").find(".toggle-img-option").on("click", function () {
    $(this).find(".img-option").toggle();
  })

  /* Hide img options and rename field on click on the screen */

  $(document).mouseup(function (e) {
    e.stopPropagation();
    let option_is_clicked = false
    $(".img-option span").each(function () {
      if (!($(this).has(e.target).length === 0)) {
        option_is_clicked = true;
      }
    })
    if (!option_is_clicked) {
      $(".search-line").find(".img-option").hide();
    }
  });
}

function fillSummaryResult(summary) {
  $(".user-list").empty();

  $(".summary-lines .summary-data").html(summary.nbLinesText);
  $(".summary-weight .summary-data").html(unit_MB.replace("%s", summary.filesizeMb));
  $(".summary-users .summary-data").html(summary.usersText);
  $(".summary-guests .summary-data").html(summary.guestsText);

  if (summary.nbGuests > 0) {
    $(".summary-guests .summary-data").addClass("icon-plus-circled").on("click", function () {
      if (current_param.user_id == "-1") {
        current_param.user_id = guest_id;
        addGuestFilter(str_guest);
        fillHistoryResult(current_param);
      }
    }).hover(function () {
      $(this).css({
        cursor : "pointer"
      })
    });

    $(".summary-guests").show();
  } else {
    $(".summary-guests").hide();
  }

  var user_dot_title = summary.members.map(member => member.username).join(", ");
  $(".user-dot").attr("title", user_dot_title).addClass("tiptip");

  var tmp = 0;
  $(".user-dot").hide();
  // summary.members is already ordered most-active-first
  summary.members.forEach(member => {
    if (tmp < 5) {
      new_user_item = $("#-2").clone();

      new_user_item.removeClass("hide");
      new_user_item.find(".user-item-name").html(member.username);
      new_user_item.data("user-id", member.userId);

      new_user_item.on("click", function () {
        if (current_param.user_id != member.userId) {
          current_param.user_id = $(this).data("user-id");
          addUserFilter(member.username)
          fillHistoryResult(current_param);
        }
      })
      $(".user-list").append(new_user_item);
      tmp++;
    } else {
      $(".user-dot").show();
    }
  });
}

function showResults(doShow) {
  console.log("EMPTY");
  if (doShow) {
    $(".search-summary").show();
    $(".container").show();
  } else {
    $(".search-summary").hide();
    $(".container").hide();
  }
}

function fillHistoryResult(ajaxParam) {
  $.ajax({
    url: "api/v1/history/search",
    data: ajaxParam,
    beforeSend: function () {
      showResults(false);
      $(".loading").removeClass("hide");
      $(".noResults").hide();
      $(".tab").empty();
    },
    success: function (raw_data) {

      data = raw_data.lines;
      maxPage = raw_data.maxPage;
      summary = raw_data.summary;

      //clear lines before refill

      if (data.length > 0) {
        var id = 0;
        data.forEach(line => {
          lineConstructor(line, id)
          id++
        });

        fillSummaryResult(summary);
        showResults(true);
        $(".noResults").hide();
      } else {
        showResults(false);
        $(".noResults").show();
      }

    },
    error: function (e) {
      console.log(e);
    }
  }).done(() => {
    activateLineOptions();
    $(".loading").addClass("hide");
    updatePagination(maxPage);
    $('.tiptip').tipTip({
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3
    });
  })
}

function lineConstructor(line, id) {
  let newLine = $("#-1").clone();

  let sections = [
    "categories",
    "tags",
    "best_rated",
    "memories-1-year-ago",
    "list",
    "search",
    "most_visited",
    "recent_pics",
    "recent_cats",
    "favorites"
  ]

  let icons = [
    "line-icon icon-folder-open icon-yellow",
    "line-icon icon-tags icon-blue",
    "line-icon icon-star icon-green",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-dice-solid icon-purple",
    "line-icon icon-search icon-purple",
    "line-icon icon-fire icon-red",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-heart icon-red"
  ];

  newLine.removeClass("hide");

  /* console log to help debug */
  // console.log(line);
  newLine.attr("id", id);
  // console.log(id);

  newLine.find(".date-day").html(line.dateFormatted);
  newLine.find(".date-hour").html(line.time);

  newLine.find(".user-name").html(line.username + '<i class="add-filter icon-plus-circled"></i>');

  newLine.find(".user-name").attr("id", line.userId);
  if (current_param.user_id == "-1") {
    newLine.find(".user-name").on("click", function ()  {
      current_param.user_id = $(this).attr('id') + "";
      current_param.pageNumber = 0;
      addUserFilter($(this).html());
      fillHistoryResult(current_param);
    })
  }

  newLine.find(".user-ip").html(line.ip + '<i class="add-filter icon-plus-circled"></i>');
  newLine.find(".user-ip").data("ip", line.ip);
  if (current_param.ip == "") {
    newLine.find(".user-ip").on("click", function () {
      current_param.ip = $(this).data("ip");
      current_param.pageNumber = 0;
      addIpFilter($(this).html());
      fillHistoryResult(current_param);
    })
  }

  newLine.find(".add-img-as-filter").data("img-id", line.imageId);
  if (current_param.image_id == "") {
    newLine.find(".add-img-as-filter").on("click", function () {
      current_param.image_id = $(this).data("img-id");
      current_param.pageNumber = 0;
      addImageFilter($(this).data("img-id"));
      fillHistoryResult(current_param);
    });
  }

  if (line.imageEditUrl) {
    newLine.find(".edit-img").attr("href", line.imageEditUrl);
  } else {
    newLine.find(".edit-img")
      .attr("href", "#")
      .addClass("notClickable tiptip")
      .attr('title', str_no_longer_exist_photo)
      .on("click", (e) => {
      e.preventDefault();
    });
  }

  switch (line.SECTION) {
    case "tags":
      if (line.tagNames.length > 1 && line.tagNames.length <= 2  ) {
        newLine.find(".type-name").html(line.tagNames[0] +", "+ line.tagNames[1] + ", ...");
        newLine.find(".type-id").html("#" + line.tagIds[0] +", "+ line.tagIds[1] + ", ...");
      } else if (line.tagNames.length > 2) {
        newLine.find(".type-name").html(line.tagNames[0] +", "+ line.tagNames[1] +", "+ line.tagNames[2]  + ", ...");
        newLine.find(".type-id").html("#" + line.tagIds[0] +", "+ line.tagIds[1] +", "+ line.tagIds[2] + ", ...");
      } else {
        newLine.find(".type-name").html(line.tagNames[0]);
        newLine.find(".type-id").html("#" + line.tagIds[0]);
      }

      let detail_str = "";
      line.tagNames.forEach(tag => {
        detail_str += tag + ", ";
      });
      detail_str = detail_str.slice(0, -2)
      newLine.find(".detail-item-1").html(detail_str);
      newLine.find(".detail-item-1").attr("title", detail_str).removeClass("hide").addClass('icon-tags');;
      break;
    
    case "most_visited":
      newLine.find(".type-name").html(str_most_visited);
      newLine.find(".detail-item-1").html(str_most_visited).addClass('icon-fire');
      newLine.find(".type-id").hide();
      break;
    case "best_rated":
      newLine.find(".type-name").html(str_best_rated);
      newLine.find(".detail-item-1").html(str_best_rated).addClass("icon-star");
      newLine.find(".type-id").hide();
      break;
    case "list":
      newLine.find(".type-name").html(str_list);
      newLine.find(".detail-item-1").html(str_list).addClass('icon-dice-solid');
      newLine.find(".type-id").hide();
      break;
    case "search":
      // for debug
      // console.log('search n° : ', line.searchId, ' ', line.searchDetails);
      const search_details = line.searchDetails;
      const search_icons = {
        'allwords': 'gallery-icon-search',
        'tags': 'gallery-icon-tag',
        'datePosted': 'gallery-icon-calendar-plus',
        'cat': 'gallery-icon-album',
        'author': 'gallery-icon-user-edit',
        'addedBy': 'gallery-icon-user',
        'filetypes': 'gallery-icon-file-image',
      }
      newLine.find(".type-name").html(line.section);
      newLine.find(".type-id").html("#" + line.searchId);
      if (!line.searchId)
      {
        newLine.find(".type-id").hide();
      }

      if (!search_details) 
      {
        newLine.find(".detail-item-1").hide();
        break; 
      }
      let active_search_details = {};
      Object.keys(search_details).forEach(key => {
          if (search_details[key] !== null) {
            active_search_details[key] = search_details[key];
          }
      });
      let count_item = 1;
      let active_more = [];
      const active_items = Object.keys(active_search_details);
      if (active_items.length > 0)
      {
        if (active_search_details.allwords)
        {
          newLine.find(".detail-item-" + count_item).html(active_search_details.allwords.join(' ')).addClass(search_icons.allwords + ' tiptip');
          newLine.find(".detail-item-" + count_item).attr('title', '<b>' + str_search_details['allwords'] + ' :</b> ' + active_search_details.allwords.join(' '));
          count_item++;
          active_more.push('allwords');
        }
        if (active_search_details.cat)
        {
          const array_cat = Object.values(active_search_details.cat);
          const cat = array_cat.join(' + ');
          let temp_div = $('<div>').html(cat);
          let text = temp_div.text().trim();
          newLine.find(".detail-item-" + count_item).html(cat).addClass(search_icons.cat + ' tiptip');
          newLine.find(".detail-item-"+ count_item).attr('title','<b>' + str_search_details['cat'] + ' :</b> ' + text).removeClass("hide");
          count_item++;
          active_more.push('cat');
        }
        if (count_item <= 2 && active_search_details.tags)
        {
          const array_tags = Object.values(active_search_details.tags);
          newLine.find(".detail-item-" + count_item).html(array_tags.join(' + ')).addClass(search_icons.tags + ' tiptip');
          newLine.find(".detail-item-"+ count_item).attr('title', '<b>' + str_search_details['tags'] + ' :</b> ' + array_tags.join(' + ')).removeClass("hide");
          count_item++;
          active_more.push('tags');
        }
        if (count_item <= 2)
        {
          let badge_to_add = active_items.length == 1 ? 1 : count_item == 1 ? 2 : 1;
          let badge_added = 0;
          active_items.some(key => {
            if (key !== 'allwords' && key !== 'cat' && key !== 'tags') {
              let array_key;
              if (Array.isArray(active_search_details[key]))
              {
                array_key = active_search_details[key];
              }
              else if (typeof active_search_details[key] === 'object')
              {
                array_key = Object.values(active_search_details[key]);
              }
              else
              {
                array_key = [active_search_details[key]];
              }
              newLine.find(".detail-item-" + count_item).html(array_key.join(' + ')).addClass(search_icons[key] + ' tiptip');
              newLine.find(".detail-item-" + count_item).attr('title', '<b>' + str_search_details[key] + ' :</b> ' + array_key.join(' + ')).removeClass("hide");
              count_item++;
              badge_added++;
              active_more.push(key);
              if (badge_added === badge_to_add) {
                return true;
              }
            }
            return false;
          });
        }
      }
      else
      {
        newLine.find(".detail-item-1").hide();
      }
      if (active_items.length >= 3) 
      {
        let count_more = 0;
        let search_details_str = Object.entries(active_search_details)
        .filter(([key]) => !active_more.includes(key))
          .map(([key, value]) => {
            let value_str;
            if(Array.isArray(value)) {
              value_str = value.join(' + ');
            } else if (typeof value === 'object') {
              value_str = Object.entries(value).map(([k, v]) => v).join(' + ');
            } else {
              value_str = value;
            }

            if (key == 'cat')
            {
              let temp_div = $('<div>').html(value_str);
              let text = temp_div.text().trim();
              value_str = text;
            }
            count_more++;
            return `<b>${str_search_details[key]}</b> : ${value_str}`;
          }).join(' <br />');
        newLine.find(".detail-item-3").html(sprintf(str_and_more, count_more)).addClass('icon-info-circled-1 tiptip');
        newLine.find(".detail-item-3").attr('title', search_details_str).removeClass('hide');
      }
      break;
    case "favorites":
      newLine.find(".type-name").html(str_favorites);
      newLine.find(".detail-item-1").html(str_favorites).addClass('icon-heart');
      newLine.find(".type-id").hide();
      break;
    case "recent_cats":
      newLine.find(".type-name").html(str_recent_cats);
      newLine.find(".detail-item-1").html(str_recent_cats).addClass('icon-clock');
      newLine.find(".type-id").hide();
      break;
    case "recent_pics":
      newLine.find(".type-name").html(str_recent_pics);
      newLine.find(".detail-item-1").html(str_recent_pics).addClass('icon-clock');
      newLine.find(".type-id").hide();
      break;
    case "categories":
      newLine.find(".type-name").html(line.categoryName);
      newLine.find(".detail-item-1").html(line.categoryName).addClass("icon-folder-open tiptip").attr("title", line.categoryPath);
      if (!line.imageThumbnailUrl) {
        newLine.find(".type-id").hide();
      }
      break;
    case "memories-1-year-ago":
      newLine.find(".type-name").html(str_memories);
      newLine.find(".detail-item-1").html(str_memories).addClass('icon-clock');
      newLine.find(".type-id").hide();
    break;
    case "contact":
      newLine.find(".type-icon i").addClass("line-icon icon-mail-1 icon-yellow");
      newLine.find(".type-name").html(str_contact_form);
      newLine.find(".detail-item-1").html(str_contact_form);
      newLine.find(".type-id").hide();
    break;
    default:
      newLine.find(".type-icon i").addClass("line-icon icon-help-puzzle icon-grey");
      newLine.find(".type-name").html(line.section);
      newLine.find(".type-id").hide();
    break;
  }

  if (line.imageThumbnailUrl) {
    const img = $("<img>").attr("src", line.imageThumbnailUrl).attr("alt", line.imageLabel || "").attr("title", line.imageLabel || "");
    newLine.find(".type-name").html(line.imageLabel);
    newLine.find(".type-icon").empty().append(img);
    newLine.find(".type-id").html("#" + line.imageId);
    newLine.find(".type-icon").attr("href", line.imageEditUrl).removeClass("no-img")
    newLine.find(".type-icon img").attr("title", str_edit_img).addClass("tiptip")
    newLine.find(".type-id").show();
  } else {
    newLine.find(".type-icon .icon-file-image").removeClass("icon-file-image");
    newLine.find(".toggle-img-option").hide();

    if (sections.indexOf(line.section) != -1) {
      var lineIconClass = icons[sections.indexOf(line.section)];
      newLine.find(".type-icon i").addClass(lineIconClass)
    } else {
      console.log("Unhandled section : " + line.section);
    }
  }

  newLine.find(".detail-item-1").removeClass("hide");
  if (line.imageType == "high") {
    newLine.find(".detail-item-1").html(str_dwld).addClass("icon-blue").removeClass("detail-item-1").removeClass("hide");
    newLine.find(".date-dwld-icon").addClass("icon-blue icon-floppy")
  } else {
    newLine.find(".date-dwld-icon").remove();
  }
  displayLine(newLine);
}

function displayLine(line) {
  $(".tab").append(line);
}

function addUserFilter(username) {
  var newFilter = $("#default-filter").clone();
  newFilter.removeClass("hide");

  newFilter.find(".filter-title").html(username);
  newFilter.find(".filter-icon").addClass("icon-user");

  newFilter.find(".remove-filter").on("click", function () {
    $(this).parent().remove();

    current_param.user_id = "-1";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
    $(".summary-guests").show();
  })

  $(".summary-guests").hide();
  $(".filter-container").append(newFilter);
  checkFilters();
}

function addGuestFilter(username) {
  var newFilter = $("#default-filter").clone();
  newFilter.removeClass("hide");

  newFilter.find(".filter-title").html(username);
  newFilter.find(".filter-icon").addClass("icon-user-secret");

  newFilter.find(".remove-filter").on("click", function () {
    $(this).parent().remove();

    current_param.user_id = "-1";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  })

  $(".filter-container").append(newFilter);
  checkFilters();
}

function addIpFilter(ip) {
  var newFilter = $("#default-filter").clone();
  newFilter.removeClass("hide");

  newFilter.find(".filter-title").html(ip);
  newFilter.find(".filter-icon").html("IP ").addClass("bold");

  newFilter.find(".remove-filter").on("click", function () {
    $(this).parent().remove();

    current_param.ip = "";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  })

  $(".filter-container").append(newFilter);
  checkFilters();
}

function addImageFilter(img_id) {
  var newFilter = $("#default-filter").clone();
  newFilter.removeClass("hide");
  
  newFilter.find(".filter-title").html("Image #" + img_id);
  newFilter.find(".filter-icon").addClass("icon-picture");

  newFilter.find(".remove-filter").on("click", function () {
    $(this).parent().remove();

    current_param.image_id = "";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  })

  $(".filter-container").append(newFilter);
  checkFilters();
}

function updateArrows(actualPage, maxPage) {
  if (actualPage == 0) {
    $('.pagination-arrow.left').addClass('unavailable');
  } else {
    $('.pagination-arrow.left').removeClass('unavailable');
  }

  if (actualPage == maxPage-1) {
    $('.pagination-arrow.rigth').addClass('unavailable');
  } else {
    $('.pagination-arrow.rigth').removeClass('unavailable');
  }
}

function updatePagination(maxPage) {
  updateArrows(current_param.pageNumber, maxPage);

  $(".pagination-item-container").empty();
  $(".pagination-item-container").append(
    "<a class='actual'>"+ (current_param.pageNumber+1) + "/" + maxPage +"</a>"
  )
}

function checkFilters() {
  if ($(".filter-container")[0].childElementCount - 1 > 0) { //Check if there are filters
    $(".filter-tags label").show();
  } else {
    $(".filter-tags label").hide();
  }
}