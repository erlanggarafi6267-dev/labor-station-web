const API_URL = (
  process.env.NEXT_PUBLIC_API_URL ||
  "http://localhost:8000/api"
).replace(/\/$/, "");

function getCookie(name: string): string | null {
  if (typeof document === "undefined") {
    return null;
  }

  const cookie = document.cookie
    .split("; ")
    .find((item) => item.startsWith(`${name}=`));

  return cookie
    ? decodeURIComponent(cookie.substring(name.length + 1))
    : null;
}

async function getCsrfToken(): Promise<string> {
  let token = getCookie("XSRF-TOKEN");

  if (!token) {
    const csrfUrl = API_URL.replace(/\/api$/, "");

    const response = await fetch(
      `${csrfUrl}/sanctum/csrf-cookie`,
      {
        method: "GET",
        credentials: "include",
        headers: {
          Accept: "application/json",
        },
      }
    );

    if (!response.ok) {
      throw new Error("Gagal menginisialisasi keamanan sesi.");
    }

    token = getCookie("XSRF-TOKEN");
  }

  if (!token) {
    throw new Error("CSRF token tidak ditemukan.");
  }

  return token;
}

export async function apiRequest(
  path: string,
  options: RequestInit = {}
): Promise<Response> {
  const method = (options.method || "GET").toUpperCase();
  const headers = new Headers(options.headers);

  headers.set("Accept", "application/json");

  if (options.body && !(options.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    const csrfToken = await getCsrfToken();
    headers.set("X-XSRF-TOKEN", csrfToken);
  }

  return fetch(`${API_URL}${path}`, {
    ...options,
    method,
    headers,
    credentials: "include",
    cache: "no-store",
  });
}