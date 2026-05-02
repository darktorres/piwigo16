import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';

// `type` and `width` are runtime-supported GLightbox options but missing
// from the upstream Options type, hence the cast.
GLightbox({ selector: '.help-popin', type: 'inline', width: '500px' } as Parameters<typeof GLightbox>[0]);
