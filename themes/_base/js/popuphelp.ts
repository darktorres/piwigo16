// When the help template is loaded into a popup window, swap home for close.
if (window.opener !== null || window.name !== '') {
    const closeLink = document.getElementById('closeLink');
    if (closeLink) closeLink.style.display = '';
    const homeLink = document.getElementById('homeLink');
    if (homeLink) homeLink.style.display = 'none';
}
