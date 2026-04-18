import { UsersCache } from './LocalStorageCache.js';
import { initModule } from './moduleInit.js';
import TomSelect from 'tom-select';

export function init(cfg) {
  const {
    usersServerKey = '',
    usersServerId = '',
    rootUrl = '',
    usersKey = 'Users',
    nbUsers = 0,
    actionTypes = {},
    actionInfos = {}
  } = cfg;

  const usersCache = new UsersCache({
    serverKey: usersServerKey,
    serverId: usersServerId,
    rootUrl: rootUrl
  });

  const color_icons = ["icon-red", "icon-blue", "icon-yellow", "icon-purple", "icon-green"];
  let activity_page = 1;
  let actual_page = 1;
  let max_page = 1;
  let uid_filter;
  const page_ellipsis = '<span>...</span>';
  const page_item = '<a data-page="%d">%d</a>';
  const users_key = usersKey;

  // Action type strings
  const actionType_add = actionTypes.add || 'add';
  const actionType_delete = actionTypes.delete || 'deletion';
  const actionType_move = actionTypes.move || 'move';
  const actionType_edit = actionTypes.edit || 'edit';
  const actionType_login = actionTypes.login || 'login';
  const actionType_logout = actionTypes.logout || 'logout';

  // Album messages
  const actionInfos_album_added = actionInfos.album?.added || '%d album added';
  const actionInfos_album_deleted = actionInfos.album?.deleted || '%d album deleted';
  const actionInfos_album_edited = actionInfos.album?.edited || '%d album edited';
  const actionInfos_album_moved = actionInfos.album?.moved || '%d album moved';
  const actionInfos_albums_added = actionInfos.album?.addedPlural || '%d albums added';
  const actionInfos_albums_deleted = actionInfos.album?.deletedPlural || '%d albums deleted';
  const actionInfos_albums_edited = actionInfos.album?.editedPlural || '%d albums edited';
  const actionInfos_albums_moved = actionInfos.album?.movedPlural || '%d albums moved';

  // User messages
  const actionInfos_user_added = actionInfos.user?.added || '%d user added';
  const actionInfos_user_deleted = actionInfos.user?.deleted || '%d user deleted';
  const actionInfos_user_edited = actionInfos.user?.edited || '%d user edited';
  const actionInfos_user_logged_in = actionInfos.user?.loggedIn || '%d user logged in';
  const actionInfos_user_logged_out = actionInfos.user?.loggedOut || '%d user logged out';
  const actionInfos_users_added = actionInfos.user?.addedPlural || '%d users added';
  const actionInfos_users_deleted = actionInfos.user?.deletedPlural || '%d users deleted';
  const actionInfos_users_edited = actionInfos.user?.editedPlural || '%d users edited';
  const actionInfos_users_logged_in = actionInfos.user?.loggedInPlural || '%d users logged in';
  const actionInfos_users_logged_out = actionInfos.user?.loggedOutPlural || '%d users logged out';

  // Photo messages
  const actionInfos_photo_added = actionInfos.photo?.added || '%d photo added';
  const actionInfos_photo_deleted = actionInfos.photo?.deleted || '%d photo deleted';
  const actionInfos_photo_edited = actionInfos.photo?.edited || '%d photo edited';
  const actionInfos_photo_moved = actionInfos.photo?.moved || '%d photo moved';
  const actionInfos_photos_added = actionInfos.photo?.addedPlural || '%d photos added';
  const actionInfos_photos_deleted = actionInfos.photo?.deletedPlural || '%d photos deleted';
  const actionInfos_photos_edited = actionInfos.photo?.editedPlural || '%d photos edited';
  const actionInfos_photos_moved = actionInfos.photo?.movedPlural || '%d photos moved';

  // Group messages
  const actionInfos_group_added = actionInfos.group?.added || '%d group added';
  const actionInfos_group_deleted = actionInfos.group?.deleted || '%d group deleted';
  const actionInfos_group_edited = actionInfos.group?.edited || '%d group edited';
  const actionInfos_group_moved = actionInfos.group?.moved || '%d group moved';
  const actionInfos_groups_added = actionInfos.group?.addedPlural || '%d groups added';
  const actionInfos_groups_deleted = actionInfos.group?.deletedPlural || '%d groups deleted';
  const actionInfos_groups_edited = actionInfos.group?.editedPlural || '%d groups edited';
  const actionInfos_groups_moved = actionInfos.group?.movedPlural || '%d groups moved';

  // Tag messages
  const actionInfos_tag_added = actionInfos.tag?.added || '%d tag added';
  const actionInfos_tag_deleted = actionInfos.tag?.deleted || '%d tag deleted';
  const actionInfos_tag_edited = actionInfos.tag?.edited || '%d tag edited';
  const actionInfos_tag_moved = actionInfos.tag?.moved || '%d tag moved';
  const actionInfos_tags_added = actionInfos.tag?.addedPlural || '%d tags added';
  const actionInfos_tags_deleted = actionInfos.tag?.deletedPlural || '%d tags deleted';
  const actionInfos_tags_edited = actionInfos.tag?.editedPlural || '%d tags edited';
  const actionInfos_tags_moved = actionInfos.tag?.movedPlural || '%d tags moved';

  get_user_activity(activity_page, uid_filter);

  function get_user_activity(page, uid) {
    const tab = document.querySelector('.tab');
    if (tab) {
      Array.from(tab.children).forEach(function(child) {
        if (child.id !== '-1' && !child.classList.contains('loading')) child.remove();
      });
    }
    document.querySelectorAll(".loading").forEach(function(el) { el.style.display = ''; });
    document.querySelectorAll('.pagination-arrow.right').forEach(function(el) { el.classList.add('unavailable'); });
    document.querySelectorAll('.pagination-arrow.left').forEach(function(el) { el.classList.add('unavailable'); });
    document.querySelectorAll(".pagination-item-container").forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll(".user-update-spinner").forEach(function(el) { el.classList.add('icon-spin6'); });

    fetch("ws.php?format=json&method=pwg.activity.getList", {
      method: "POST",
      body: new URLSearchParams({ page: page - 1, uid: uid }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      uid_filter = uid;

      setCreationDate(
        data.result['result_lines'][data.result['result_lines'].length - 1].date,
        data.result['result_lines'][0].date
      );
      document.querySelectorAll(".loading").forEach(function(el) { el.style.display = 'none'; });

      data.result['result_lines'].forEach(function(line) { lineConstructor(line); });

      max_page = data.result['max_page'];
      document.querySelectorAll(".user-update-spinner").forEach(function(el) { el.classList.remove('icon-spin6'); });
      document.querySelectorAll(".pagination-item-container").forEach(function(el) { el.style.display = ''; });
      update_pagination_menu();
    })
    .catch(function(e) {
      console.log("ajax call failed");
      console.log(e);
    });
  }

  function lineConstructor(line) {
    const newLine = document.getElementById("-1").cloneNode(true);
    newLine.classList.remove("hide");
    newLine.id = line.id;

    let final_albumInfos;

    if (line.counter > 1) {
      switch (line.action) {
        case "edit":
          newLine.querySelector(".action-type")?.classList.add("icon-blue");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-pencil");
          newLine.querySelector(".action-name").innerHTML = actionType_edit;
          final_albumInfos = line.object === "user" ? actionInfos_users_edited :
                            line.object === "album" ? actionInfos_albums_edited :
                            line.object === "group" ? actionInfos_groups_edited :
                            line.object === "photo" ? actionInfos_photos_edited :
                            line.object === "tag" ? actionInfos_tags_edited :
                            line.counter + " " + line.object + " " + line.action;
          final_albumInfos = final_albumInfos.replace('%d', line.counter);
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "add":
          newLine.querySelector(".action-type")?.classList.add("icon-green");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-plus");
          newLine.querySelector(".action-name").innerHTML = actionType_add;
          final_albumInfos = line.object === "user" ? actionInfos_users_added :
                            line.object === "album" ? actionInfos_albums_added :
                            line.object === "group" ? actionInfos_groups_added :
                            line.object === "photo" ? actionInfos_photos_added :
                            line.object === "tag" ? actionInfos_tags_added :
                            line.counter + " " + line.object + " " + line.action;
          final_albumInfos = final_albumInfos.replace('%d', line.counter);
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "delete":
          newLine.querySelector(".action-type")?.classList.add("icon-red");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-trash-1");
          newLine.querySelector(".action-name").innerHTML = actionType_delete;
          final_albumInfos = line.object === "user" ? actionInfos_users_deleted :
                            line.object === "album" ? actionInfos_albums_deleted :
                            line.object === "group" ? actionInfos_groups_deleted :
                            line.object === "photo" ? actionInfos_photos_deleted :
                            line.object === "tag" ? actionInfos_tags_deleted :
                            line.counter + " " + line.object + " " + line.action;
          final_albumInfos = final_albumInfos.replace('%d', line.counter);
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "move":
          newLine.querySelector(".action-type")?.classList.add("icon-yellow");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-move");
          newLine.querySelector(".action-name").innerHTML = actionType_move;
          final_albumInfos = line.object === "album" ? actionInfos_albums_moved :
                            line.object === "group" ? actionInfos_groups_moved :
                            line.object === "photo" ? actionInfos_photos_moved :
                            line.object === "tag" ? actionInfos_tags_moved :
                            line.counter + " " + line.object + " " + line.action;
          final_albumInfos = final_albumInfos.replace('%d', line.counter);
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "login":
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-key");
          newLine.querySelector(".action-section")?.classList.add("icon-user-1");
          newLine.querySelector(".action-name").innerHTML = actionType_login;
          final_albumInfos = actionInfos_users_logged_in.replace('%d', line.counter);
          break;
        case "logout":
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          if (line.user_id != 2) {
            newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          } else {
            newLine.querySelector(".user-pic")?.classList.add(color_icons[line.object_id[0] % 5]);
          }
          newLine.querySelector(".action-icon")?.classList.add("icon-logout");
          newLine.querySelector(".action-section")?.classList.add("icon-user-1");
          newLine.querySelector(".action-name").innerHTML = actionType_logout;
          final_albumInfos = actionInfos_users_logged_out.replace('%d', line.counter);
          break;
        default:
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
      }
    } else {
      switch (line.action) {
        case "edit":
          newLine.querySelector(".action-type")?.classList.add("icon-blue");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-pencil");
          newLine.querySelector(".action-name").innerHTML = actionType_edit;
          final_albumInfos = line.object === "user" ? actionInfos_user_edited :
                            line.object === "album" ? actionInfos_album_edited :
                            line.object === "group" ? actionInfos_group_edited :
                            line.object === "photo" ? actionInfos_photo_edited :
                            line.object === "tag" ? actionInfos_tag_edited :
                            line.counter + " " + line.object + " " + line.action;
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "add":
          newLine.querySelector(".action-type")?.classList.add("icon-green");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-plus");
          newLine.querySelector(".action-name").innerHTML = actionType_add;
          final_albumInfos = line.object === "user" ? actionInfos_user_added :
                            line.object === "album" ? actionInfos_album_added :
                            line.object === "group" ? actionInfos_group_added :
                            line.object === "photo" ? actionInfos_photo_added :
                            line.object === "tag" ? actionInfos_tag_added :
                            line.counter + " " + line.object + " " + line.action;
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "delete":
          newLine.querySelector(".action-type")?.classList.add("icon-red");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-trash-1");
          newLine.querySelector(".action-name").innerHTML = actionType_delete;
          final_albumInfos = line.object === "user" ? actionInfos_user_deleted :
                            line.object === "album" ? actionInfos_album_deleted :
                            line.object === "group" ? actionInfos_group_deleted :
                            line.object === "photo" ? actionInfos_photo_deleted :
                            line.object === "tag" ? actionInfos_tag_deleted :
                            line.counter + " " + line.object + " " + line.action;
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "user" ? "icon-user-1" :
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "move":
          newLine.querySelector(".action-type")?.classList.add("icon-yellow");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-move");
          newLine.querySelector(".action-name").innerHTML = actionType_move;
          final_albumInfos = line.object === "album" ? actionInfos_album_moved :
                            line.object === "group" ? actionInfos_group_moved :
                            line.object === "photo" ? actionInfos_photo_moved :
                            line.object === "tag" ? actionInfos_tag_moved :
                            line.counter + " " + line.object + " " + line.action;
          newLine.querySelector(".action-section")?.classList.add(
            line.object === "album" ? "icon-folder-open" :
            line.object === "group" ? "icon-users-1" :
            line.object === "photo" ? "icon-picture" :
            line.object === "tag" ? "icon-tags" : ""
          );
          break;
        case "login":
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          newLine.querySelector(".action-icon")?.classList.add("icon-key");
          newLine.querySelector(".action-section")?.classList.add("icon-user-1");
          newLine.querySelector(".action-name").innerHTML = actionType_login;
          final_albumInfos = actionInfos_user_logged_in;
          break;
        case "logout":
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          if (line.user_id != 2) {
            newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
          } else {
            newLine.querySelector(".user-pic")?.classList.add(color_icons[line.object_id[0] % 5]);
          }
          newLine.querySelector(".action-icon")?.classList.add("icon-logout");
          newLine.querySelector(".action-section")?.classList.add("icon-user-1");
          newLine.querySelector(".action-name").innerHTML = actionType_logout;
          final_albumInfos = actionInfos_user_logged_out;
          break;
        default:
          newLine.querySelector(".action-type")?.classList.add("icon-purple");
          newLine.querySelector(".user-pic")?.classList.add(color_icons[line.user_id % 5]);
      }
    }

    newLine.querySelector(".action-infos-test").innerHTML = final_albumInfos;
    newLine.querySelector(".nb_items").innerHTML = line.counter;
    newLine.querySelector(".date-day").innerHTML = line.date;
    newLine.querySelector(".date-hour").innerHTML = line.hour;
    newLine.querySelector(".user-name").innerHTML = line.username;
    newLine.querySelector(".user-pic").innerHTML = get_initials(line.username);
    newLine.querySelector(".detail-item-1").innerHTML = line.ip_address;
    newLine.querySelector(".detail-item-1")?.setAttribute("title", "IP");

    if (line.detailsType == "script") {
      newLine.querySelector(".detail-item-2").innerHTML = line.details.script;
      newLine.querySelector(".detail-item-2")?.setAttribute('title', 'Script');
    } else if (line.detailsType == "method") {
      newLine.querySelector(".detail-item-2").innerHTML = line.details.method;
      newLine.querySelector(".detail-item-2")?.setAttribute('title', 'API Method');
    }

    if (line.details.agent) {
      newLine.querySelector(".detail-item-3").innerHTML = line.details.agent;
      newLine.querySelector(".detail-item-3")?.setAttribute('title', line.details.agent);
    } else if (line.details.users_string && line.action != "logout" && line.action != "login") {
      newLine.querySelector(".detail-item-3").innerHTML = line.details.users_string;
      newLine.querySelector(".detail-item-3")?.setAttribute('title', users_key + ": " + line.details.users_string);
    } else {
      newLine.querySelector(".detail-item-3")?.remove();
    }

    newLine.classList.add("uid-" + line.user_id);
    displayLine(newLine);
  }

  function displayLine(line) {
    const tab = document.querySelector(".tab");
    if (tab) tab.appendChild(line);
  }

  function get_initials(username) {
    const words = username.toUpperCase().split(" ");
    let res = words[0][0];
    if (words.length > 1 && words[1][0] !== undefined) {
      res += words[1][0];
    }
    return res;
  }

  function setCreationDate(startDate, endDate) {
    document.querySelectorAll(".start-date").forEach(function(el) { el.innerHTML = startDate; });
    document.querySelectorAll(".end-date").forEach(function(el) { el.innerHTML = endDate; });
  }

  function move_to_page(page) {
    if (page < 0 || page > max_page) return;
    actual_page = page;
    update_pagination_menu();
    get_user_activity(page, uid_filter);
  }

  document.querySelectorAll('.pagination-arrow.right').forEach(function(el) {
    el.addEventListener('click', () => { move_to_page(actual_page + 1); });
  });

  document.querySelectorAll('.pagination-arrow.left').forEach(function(el) {
    el.addEventListener('click', () => { move_to_page(actual_page - 1); });
  });

  function update_pagination_menu() {
    updateArrows();
    update_pagination_items();
    if (max_page <= 1) {
      document.querySelectorAll('.pagination-container').forEach(function(el) { el.style.display = 'none'; });
    } else {
      document.querySelectorAll('.pagination-container').forEach(function(el) { el.style.display = ''; });
    }
  }

  function updateArrows() {
    if (actual_page == 1) {
      document.querySelectorAll('.pagination-arrow.left').forEach(function(el) { el.classList.add('unavailable'); });
    } else {
      document.querySelectorAll('.pagination-arrow.left').forEach(function(el) { el.classList.remove('unavailable'); });
    }
    if (actual_page == max_page) {
      document.querySelectorAll('.pagination-arrow.right').forEach(function(el) { el.classList.add('unavailable'); });
    } else {
      document.querySelectorAll('.pagination-arrow.right').forEach(function(el) { el.classList.remove('unavailable'); });
    }
  }

  function update_pagination_items() {
    document.querySelectorAll('.pagination-item-container a').forEach(function(el) { el.remove(); });
    document.querySelectorAll('.pagination-item-container span').forEach(function(el) { el.remove(); });

    append_pagination_item(1);
    if (actual_page > 2) {
      append_pagination_item();
    }
    if (actual_page != 1 && actual_page != max_page) {
      append_pagination_item(actual_page);
    }
    if (actual_page < (max_page - 1)) {
      append_pagination_item();
    }
    append_pagination_item(max_page);
  }

  function append_pagination_item(page = null) {
    const container = document.querySelector('.pagination-item-container');
    if (!container) return;
    if (page != null) {
      container.insertAdjacentHTML('beforeend', page_item.replace(/%d/g, page));
      const new_tag = container.lastElementChild;
      if (actual_page == page) {
        new_tag.classList.add('actual');
      }
      new_tag.addEventListener('click', function() {
        move_to_page(parseInt(this.dataset.page));
      });
    } else {
      container.insertAdjacentHTML('beforeend', page_ellipsis);
    }
  }

  const h1El = document.querySelector("h1");
  if (h1El) h1El.insertAdjacentHTML('beforeend', `<span class='badge-number'>${window.nbUsers - 1}</span>`);

  const userSelector = document.querySelector(".user-selector");
  if (userSelector) {
    new TomSelect(userSelector, {});
    if (userSelector.tomselect) userSelector.tomselect.setValue(null);
  }

  document.querySelectorAll('select').forEach(function(sel) {
    sel.addEventListener('change', function() {
      const tsControl = document.querySelector(".ts-control");
      if (tsControl && tsControl.classList.contains("full")) {
        const item = document.querySelector(".ts-control .item");
        get_user_activity(1, item ? item.dataset.value : undefined);
      }
    });
  });

  document.querySelectorAll(".cancel-icon").forEach(function(el) {
    el.addEventListener('click', function() {
      const userSel = document.querySelector(".user-selector");
      if (userSel && userSel.tomselect) userSel.tomselect.clear(true);
      document.querySelectorAll(".line").forEach(function(l) { l.style.display = 'flex'; });
    });
  });
}

initModule(init);
