export interface ApiError extends Error {
  errorCode: string;
  message: string;
  status: number;
  details?: unknown;
}
