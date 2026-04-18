// Shared Piwigo domain types — grown incrementally as modules are converted

export interface PwigoConfig {
  rootUrl: string;
  pwgToken: string;
  [key: string]: unknown;
}

export interface ImageData {
  id: number;
  name: string;
  file: string;
  [key: string]: unknown;
}

export interface AlbumData {
  id: number;
  name: string;
  fullname?: string;
  [key: string]: unknown;
}

export interface UserData {
  id: number;
  username: string;
  status: string;
  [key: string]: unknown;
}
