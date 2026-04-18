import { GroupsCache, UsersCache } from './LocalStorageCache.js';
import TomSelect from 'tom-select';

const cfg = JSON.parse(document.getElementById('pwg-page-data').textContent);

const groupsCache = new GroupsCache({
  serverKey: cfg.CACHE_KEYS.groups || '',
  serverId: cfg.CACHE_KEYS._hash,
  rootUrl: cfg.ROOT_URL
});

groupsCache.selectize(document.querySelectorAll('[data-selectize=groups]'));

const usersCache = new UsersCache({
  serverKey: cfg.CACHE_KEYS.users,
  serverId: cfg.CACHE_KEYS._hash,
  rootUrl: cfg.ROOT_URL
});

usersCache.selectize(document.querySelectorAll('[data-selectize=users]'));

function checkStatusOptions() {
  var checked = document.querySelector("input[name=status]:checked");
  var privateOptions = document.getElementById("privateOptions");
  if (privateOptions) {
    privateOptions.style.display = (checked && checked.value === "private") ? '' : 'none';
  }
}

checkStatusOptions();
var selectStatus = document.getElementById("selectStatus");
if (selectStatus) {
  selectStatus.addEventListener('change', function() { checkStatusOptions(); });
}

if (cfg.nb_users_granted_indirect && cfg.nb_users_granted_indirect > 0) {
  document.querySelectorAll(".toggle-indirectPermissions").forEach(function(el) {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      document.querySelectorAll(".toggle-indirectPermissions").forEach(function(t) {
        t.style.display = t.style.display === 'none' ? '' : 'none';
      });
      var details = document.getElementById("indirectPermissionsDetails");
      if (details) details.style.display = details.style.display === 'none' ? '' : 'none';
    });
  });
}
