import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';

(window as Window & { GLightbox: typeof GLightbox }).GLightbox = GLightbox;

export {};
