    "use client";

    import { FormEvent, useCallback, useEffect, useState } from "react";

const API_URL = (
    process.env.NEXT_PUBLIC_API_URL ||
    "http://localhost:8000/api"
).replace(/\/$/, "");

function getCookie(name: string): string | null {
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

    const response = await fetch(`${csrfUrl}/sanctum/csrf-cookie`, {
      method: "GET",
      credentials: "include",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      throw new Error("Gagal mengambil CSRF token.");
    }

    token = getCookie("XSRF-TOKEN");
  }

  if (!token) {
    throw new Error("CSRF token tidak ditemukan.");
  }

  return token;
}

    type User = {
    id: number;
    name: string;
    email: string;
    role: string;
    };

    type AuditLog = {
    id: number;
    user_id: number | null;
    action: string;
    entity_type: string;
    entity_id: number | null;
    metadata: {
        ip_address?: string;
        user_agent?: string;
        email?: string;
        name?: string;
        role?: string;
        reason?: string;
        [key: string]: unknown;
    } | null;
    created_at: string;
    user: {
        id: number;
        name: string;
        email: string;
        role: {
        name: string;
        slug: string;
        } | null;
    } | null;
    };

    type Pagination = {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    };

    type AuditResponse = {
    message: string;
    data: {
        data: AuditLog[];
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    };

    const navigation = [
    { label: "Dashboard", icon: "▦", href: "/dashboard" },
    { label: "Transaksi", icon: "▤", href: "/dashboard/transactions" },
    { label: "Produk", icon: "◈", href: "/dashboard/products" },
    { label: "Inventori", icon: "▣", href: "/dashboard/inventory" },
    { label: "Pengeluaran", icon: "◉", href: "/dashboard/expenses" },
    { label: "Laporan", icon: "▥", href: "/dashboard/reports" },
    { label: "Audit Log", icon: "◷", href: "/dashboard/activity" },
    ];

    function formatDate(value: string) {
    if (!value) return "-";

    return new Date(value).toLocaleString("id-ID", {
        day: "2-digit",
        month: "long",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
    }

    function actionLabel(action: string) {
    const labels: Record<string, string> = {
        LOGIN_SUCCESS: "Login Berhasil",
        LOGIN_FAILED: "Login Gagal",
        LOGIN_BLOCKED: "Login Diblokir",
        LOGOUT: "Logout",
    };

    return labels[action] || action.replaceAll("_", " ");
    }

    function actionColor(action: string) {
    if (action === "LOGIN_SUCCESS") {
        return "bg-emerald-100 text-emerald-800";
    }

    if (action === "LOGOUT") {
        return "bg-blue-100 text-blue-800";
    }

    if (action === "LOGIN_FAILED" || action === "LOGIN_BLOCKED") {
        return "bg-red-100 text-red-800";
    }

    return "bg-[#efe1d2] text-[#69452d]";
    }

    export default function AuditActivityPage() {
    const [user, setUser] = useState<User | null>(null);
    const [logs, setLogs] = useState<AuditLog[]>([]);
    const [pagination, setPagination] = useState<Pagination>({
        current_page: 1,
        last_page: 1,
        total: 0,
        per_page: 15,
    });

    const [searchInput, setSearchInput] = useState("");
    const [search, setSearch] = useState("");
    const [dateFromInput, setDateFromInput] = useState("");
    const [dateToInput, setDateToInput] = useState("");
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    const [actionInput, setActionInput] = useState("");
    const [action, setAction] = useState("");

    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [loggingOut, setLoggingOut] = useState(false);
    const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);

    useEffect(() => {
    let active = true;

    async function checkSession() {
        try {
        const response = await fetch(`${API_URL}/me`, {
            method: "GET",
            credentials: "include",
            headers: {
            Accept: "application/json",
            },
            cache: "no-store",
        });

        if (!response.ok) {
            window.location.replace("/");
            return;
        }

        const result = await response.json();
        const currentUser = result.user ?? result;

        if (!currentUser.id || currentUser.role !== "owner") {
            window.location.replace("/");
            return;
        }

        if (active) {
            setUser(currentUser as User);
        }
        } catch {
        if (active) {
            window.location.replace("/");
        }
        }
    }

    checkSession();

    return () => {
        active = false;
    };
    }, []);

    const fetchLogs = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
        const params = new URLSearchParams({
        page: String(page),
        per_page: "15",
        });

        if (search) {
        params.set("search", search);
        }

        if (dateFrom) {
        params.set("date_from", dateFrom);
        }

        if (dateTo) {
        params.set("date_to", dateTo);
        }

        if (action) {
        params.set("action", action);
        }

        const response = await fetch(
        `${API_URL}/owner/audit-logs?${params.toString()}`,
        {
            method: "GET",
            credentials: "include",
            headers: {
            Accept: "application/json",
            },
            cache: "no-store",
        }
        );

        if (response.status === 401) {
        window.location.replace("/");
        return;
        }

        const result = await response.json();

        if (!response.ok) {
        throw new Error(
            result.message || "Gagal mengambil riwayat aktivitas."
        );
        }

        const responseData = result as AuditResponse;

        setLogs(responseData.data.data || []);

        setPagination({
        current_page: responseData.data.current_page,
        last_page: responseData.data.last_page,
        total: responseData.data.total,
        per_page: responseData.data.per_page,
        });
    } catch (err) {
        setError(
        err instanceof Error
            ? err.message
            : "Tidak dapat terhubung ke server."
        );
    } finally {
        setLoading(false);
    }
    }, [page, search, dateFrom, dateTo, action]);

    useEffect(() => {
        if (user) fetchLogs();
    }, [user, fetchLogs]);

    function handleSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (dateFromInput && dateToInput && dateToInput < dateFromInput) {
        setError("Tanggal akhir tidak boleh lebih awal dari tanggal awal.");
        return;
        }

        setError("");
        setPage(1);
        setSearch(searchInput.trim());
        setDateFrom(dateFromInput);
        setDateTo(dateToInput);
        setAction(actionInput);
    }

    function resetFilters() {
        setSearchInput("");
        setSearch("");
        setDateFromInput("");
        setDateToInput("");
        setDateFrom("");
        setDateTo("");
        setActionInput("");
        setAction("");
        setPage(1);
        setError("");
    }

    async function handleLogout() {
    setLoggingOut(true);

    try {
        const csrfToken = await getCsrfToken();

        const response = await fetch(`${API_URL}/logout`, {
        method: "POST",
        credentials: "include",
        headers: {
            Accept: "application/json",
            "X-XSRF-TOKEN": csrfToken,
        },
        });

        if (!response.ok) {
        console.error("Logout gagal:", response.status);
        }
    } catch (error) {
        console.error("Logout error:", error);
    } finally {
        window.location.replace("/");
    }
    }

    const firstItem =
        pagination.total === 0
        ? 0
        : (pagination.current_page - 1) * pagination.per_page + 1;

    const lastItem = Math.min(
        pagination.current_page * pagination.per_page,
        pagination.total
    );

    return (
        <main className="min-h-screen bg-[#f5eee6] text-[#392619]">
        {/* MOBILE HEADER */}
        <header className="sticky top-0 z-40 flex h-[70px] items-center justify-between border-b border-[#e8d9ca] bg-[#fffaf4]/95 px-4 backdrop-blur lg:hidden">
            <button
            type="button"
            onClick={() => setSidebarOpen(true)}
            className="rounded-xl bg-[#efe1d2] px-3 py-2 text-xl text-[#543923]"
            aria-label="Buka menu"
            >
            ☰
            </button>

            <div className="text-center">
            <p className="text-sm font-black tracking-wide">LABOR_STATION</p>
            <p className="text-[10px] text-[#92775f]">Audit Log</p>
            </div>

            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-[#69452d] text-sm font-bold text-white">
            {user?.name?.charAt(0).toUpperCase() || "O"}
            </div>
        </header>

        {/* MOBILE OVERLAY */}
        {sidebarOpen && (
            <button
            type="button"
            aria-label="Tutup menu"
            onClick={() => setSidebarOpen(false)}
            className="fixed inset-0 z-40 bg-black/45 lg:hidden"
            />
        )}

        {/* SIDEBAR */}
        <aside
            className={`fixed inset-y-0 left-0 z-50 flex w-[270px] flex-col border-r border-[#543923] bg-[#302016] text-[#fff5e9] transition-transform duration-300 lg:translate-x-0 ${
            sidebarOpen ? "translate-x-0" : "-translate-x-full"
            }`}
        >
            <div className="flex h-[88px] items-center gap-3 border-b border-white/10 px-6">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#f0dfcc] text-xl text-[#543923]">
                ☕
            </div>

            <div>
                <h1 className="text-base font-black tracking-wide">
                LABOR_STATION
                </h1>
                <p className="mt-1 text-[10px] tracking-[0.18em] text-[#d8bfa5]">
                MANAGEMENT SYSTEM
                </p>
            </div>

            <button
                type="button"
                onClick={() => setSidebarOpen(false)}
                className="ml-auto text-xl text-[#d8bfa5] lg:hidden"
                aria-label="Tutup menu"
            >
                ×
            </button>
            </div>

            <div className="px-5 pt-7">
            <p className="mb-3 px-2 text-[10px] font-bold uppercase tracking-[0.2em] text-[#b99b7e]">
                Main Menu
            </p>

            <nav className="space-y-1">
                {navigation.map((item) => (
                <a
                    key={item.label}
                    href={item.href}
                    onClick={() => setSidebarOpen(false)}
                    className={`flex items-center gap-3 rounded-xl px-4 py-3 text-sm transition ${
                    item.href === "/dashboard/activity"
                        ? "bg-[#e6c5a3] font-bold text-[#392619] shadow-lg"
                        : "text-[#ead9c7] hover:bg-white/10 hover:text-white"
                    }`}
                >
                    <span className="w-6 text-center text-lg">{item.icon}</span>
                    {item.label}
                    {item.href === "/dashboard/activity" && (
                    <span className="ml-auto h-2 w-2 rounded-full bg-[#69452d]" />
                    )}
                </a>
                ))}
            </nav>
            </div>

            <div className="mt-auto border-t border-white/10 p-5">
            <div className="mb-4 rounded-xl bg-white/5 p-3">
                <p className="text-[10px] uppercase tracking-wider text-[#b99b7e]">
                Signed in as
                </p>
                <p className="mt-1 truncate text-sm font-bold">
                {user?.name || "Owner"}
                </p>
                <p className="mt-1 text-xs text-[#d8bfa5]">Administrator</p>
            </div>

            <button
                type="button"
                onClick={handleLogout}
                disabled={loggingOut}
                className="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-[#f2c6b8] transition hover:bg-red-500/10 disabled:opacity-50"
            >
                <span className="text-lg">↪</span>
                {loggingOut ? "Signing out..." : "Sign Out"}
            </button>

            <p className="mt-5 text-center text-[10px] text-[#a58c75]">
                LABOR_STATION © 2026
            </p>
            </div>
        </aside>

        {/* MAIN CONTENT */}
        <div className="min-h-screen lg:ml-[270px]">
            {/* DESKTOP HEADER */}
            <header className="hidden h-[88px] items-center justify-between border-b border-[#e8d9ca] bg-[#fffaf4] px-8 lg:flex xl:px-10">
            <div>
                <p className="text-xs text-[#92775f]">
                Workspace / Administration
                </p>
                <h2 className="mt-1 text-lg font-extrabold">Audit Log</h2>
            </div>

            <div className="flex items-center gap-3">
                <div className="text-right">
                <p className="text-sm font-bold">{user?.name || "Owner"}</p>
                <p className="text-xs text-[#92775f]">Owner Account</p>
                </div>

                <div className="flex h-11 w-11 items-center justify-center rounded-full bg-[#69452d] font-bold text-white">
                {user?.name?.charAt(0).toUpperCase() || "O"}
                </div>
            </div>
            </header>

            <div className="mx-auto max-w-[1500px] space-y-6 p-4 sm:p-6 lg:p-8 xl:p-10">
            {/* TITLE */}
            <section>
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9b795b]">
                Security & Monitoring
                </p>

                <h1 className="mt-2 text-2xl font-black tracking-tight sm:text-3xl">
                Riwayat Aktivitas
                </h1>

                <p className="mt-2 text-sm text-[#92775f]">
                Pantau aktivitas login dan logout pengguna LABOR_STATION.
                </p>
            </section>

            {/* SUMMARY */}
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm">
                <p className="text-sm text-[#8b735e]">Total Aktivitas</p>
                <p className="mt-2 text-3xl font-black">
                    {pagination.total}
                </p>
                <p className="mt-1 text-xs text-[#a18b77]">
                    Sesuai filter yang dipilih
                </p>
                </div>

                <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm">
                <p className="text-sm text-[#8b735e]">Halaman Saat Ini</p>
                <p className="mt-2 text-3xl font-black">
                    {pagination.current_page} / {pagination.last_page}
                </p>
                <p className="mt-1 text-xs text-[#a18b77]">
                    Maksimal 15 aktivitas per halaman
                </p>
                </div>
            </section>

            {/* FILTER */}
            <section className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm sm:p-6">
                <div className="mb-5">
                <h2 className="font-extrabold">Filter Aktivitas</h2>
                <p className="mt-1 text-xs text-[#92775f]">
                    Cari pengguna, aktivitas, atau rentang tanggal.
                </p>
                </div>

                <form onSubmit={handleSearch} className="space-y-4">
                <div>
                    <label className="mb-2 block text-xs font-semibold text-[#80664e]">
                    Pencarian
                    </label>
                    <input
                    type="search"
                    value={searchInput}
                    onChange={(event) => setSearchInput(event.target.value)}
                    placeholder="Nama, email, atau jenis aktivitas..."
                    className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none transition focus:border-[#8b674d]"
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                    <label className="mb-2 block text-xs font-semibold text-[#80664e]">
                        Dari Tanggal
                    </label>
                    <input
                        type="date"
                        value={dateFromInput}
                        onChange={(event) => setDateFromInput(event.target.value)}
                        className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                    />
                    </div>

                    <div>
                    <label className="mb-2 block text-xs font-semibold text-[#80664e]">
                        Sampai Tanggal
                    </label>
                    <input
                        type="date"
                        min={dateFromInput || undefined}
                        value={dateToInput}
                        onChange={(event) => setDateToInput(event.target.value)}
                        className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                    />
                    </div>
                </div>

                <div>
                    <label className="mb-2 block text-xs font-semibold text-[#80664e]">
                    Jenis Aktivitas
                    </label>
                    <select
                    value={actionInput}
                    onChange={(event) => setActionInput(event.target.value)}
                    className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                    >
                    <option value="">Semua Aktivitas</option>
                    <option value="LOGIN_SUCCESS">Login Berhasil</option>
                    <option value="LOGIN_FAILED">Login Gagal</option>
                    <option value="LOGIN_BLOCKED">Login Diblokir</option>
                    <option value="LOGOUT">Logout</option>
                    </select>
                </div>

                <div className="flex flex-wrap gap-2">
                    <button
                    type="submit"
                    className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#69452d]"
                    >
                    Cari Aktivitas
                    </button>

                    <button
                    type="button"
                    onClick={resetFilters}
                    className="rounded-xl border border-[#ddc9b5] bg-white px-5 py-3 text-sm font-semibold text-[#69452d] transition hover:bg-[#f5eee6]"
                    >
                    Reset Filter
                    </button>

                    <button
                    type="button"
                    onClick={fetchLogs}
                    disabled={loading}
                    className="rounded-xl border border-[#ddc9b5] bg-white px-5 py-3 text-sm font-semibold text-[#69452d] disabled:opacity-50"
                    >
                    ↻ Refresh
                    </button>
                </div>
                </form>
            </section>

            {/* ERROR */}
            {error && (
                <div
                role="alert"
                className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                {error}
                </div>
            )}

            {/* TABLE */}
            <section className="overflow-hidden rounded-2xl border border-[#eadccf] bg-[#fffaf4] shadow-sm">
                <div className="flex flex-col justify-between gap-2 border-b border-[#eadccf] p-5 sm:flex-row sm:items-center">
                <div>
                    <h2 className="font-extrabold">Daftar Aktivitas</h2>
                    <p className="mt-1 text-xs text-[#92775f]">
                    Catatan aktivitas pengguna berdasarkan waktu terbaru.
                    </p>
                </div>

                <span className="text-xs text-[#92775f]">
                    Menampilkan {firstItem}–{lastItem} dari {pagination.total}
                </span>
                </div>

                {loading ? (
                <div className="p-12 text-center">
                    <div className="mx-auto h-9 w-9 animate-spin rounded-full border-4 border-[#e8d9ca] border-t-[#69452d]" />
                    <p className="mt-4 text-sm text-[#92775f]">
                    Memuat riwayat aktivitas...
                    </p>
                </div>
                ) : logs.length === 0 ? (
                <div className="p-12 text-center">
                    <p className="text-3xl">◷</p>
                    <p className="mt-3 font-bold">Belum ada aktivitas</p>
                    <p className="mt-1 text-sm text-[#92775f]">
                    Tidak ditemukan aktivitas dengan filter tersebut.
                    </p>
                </div>
                ) : (
                <>
                    {/* MOBILE CARDS */}
                    <div className="divide-y divide-[#eadccf] md:hidden">
                    {logs.map((log) => (
                        <div key={log.id} className="space-y-3 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                            <p className="font-bold">
                                {log.user?.name ||
                                log.metadata?.email ||
                                "Pengguna tidak diketahui"}
                            </p>
                            <p className="mt-1 break-all text-xs text-[#92775f]">
                                {log.user?.email || log.metadata?.email || "-"}
                            </p>
                            </div>

                            <span
                            className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-bold ${actionColor(log.action)}`}
                            >
                            {actionLabel(log.action)}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-3 text-xs">
                            <div>
                            <p className="text-[#92775f]">Role</p>
                            <p className="mt-1 font-semibold">
                                {log.user?.role?.name ||
                                log.metadata?.role ||
                                "-"}
                            </p>
                            </div>

                            <div>
                            <p className="text-[#92775f]">Alamat IP</p>
                            <p className="mt-1 break-all font-semibold">
                                {log.metadata?.ip_address || "-"}
                            </p>
                            </div>
                        </div>

                        <p className="text-xs text-[#92775f]">
                            {formatDate(log.created_at)}
                        </p>

                        <button
                            type="button"
                            onClick={() => setSelectedLog(log)}
                            className="w-full rounded-xl border border-[#ddc9b5] px-4 py-2 text-xs font-bold text-[#69452d]"
                        >
                            Lihat Detail
                        </button>
                        </div>
                    ))}
                    </div>

                    {/* DESKTOP TABLE */}
                    <div className="hidden overflow-x-auto md:block">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-[#f5eee6] text-xs text-[#80664e]">
                        <tr>
                            <th className="px-5 py-4">Pengguna</th>
                            <th className="px-5 py-4">Aktivitas</th>
                            <th className="px-5 py-4">Role</th>
                            <th className="px-5 py-4">Alamat IP</th>
                            <th className="px-5 py-4">Waktu</th>
                            <th className="px-5 py-4">Detail</th>
                        </tr>
                        </thead>

                        <tbody className="divide-y divide-[#eadccf]">
                        {logs.map((log) => (
                            <tr key={log.id} className="hover:bg-[#fcf5ed]">
                            <td className="px-5 py-4">
                                <p className="font-bold">
                                {log.user?.name ||
                                    log.metadata?.email ||
                                    "Pengguna tidak diketahui"}
                                </p>
                                <p className="mt-1 text-xs text-[#92775f]">
                                {log.user?.email || log.metadata?.email || "-"}
                                </p>
                            </td>

                            <td className="px-5 py-4">
                                <span
                                className={`inline-block rounded-full px-3 py-1 text-xs font-bold ${actionColor(log.action)}`}
                                >
                                {actionLabel(log.action)}
                                </span>
                            </td>

                            <td className="px-5 py-4 text-xs">
                                {log.user?.role?.name ||
                                log.metadata?.role ||
                                "-"}
                            </td>

                            <td className="px-5 py-4 text-xs">
                                {log.metadata?.ip_address || "-"}
                            </td>

                            <td className="whitespace-nowrap px-5 py-4 text-xs text-[#80664e]">
                                {formatDate(log.created_at)}
                            </td>

                            <td className="px-5 py-4">
                                <button
                                type="button"
                                onClick={() => setSelectedLog(log)}
                                className="rounded-lg border border-[#ddc9b5] px-3 py-2 text-xs font-bold text-[#69452d] hover:bg-[#f5eee6]"
                                >
                                Detail
                                </button>
                            </td>
                            </tr>
                        ))}
                        </tbody>
                    </table>
                    </div>
                </>
                )}

                {/* PAGINATION */}
                {!loading && pagination.total > 0 && (
                <div className="flex flex-col items-center justify-between gap-3 border-t border-[#eadccf] p-4 sm:flex-row">
                    <p className="text-xs text-[#92775f]">
                    Halaman {pagination.current_page} dari {pagination.last_page}
                    </p>

                    <div className="flex gap-2">
                    <button
                        type="button"
                        disabled={pagination.current_page <= 1}
                        onClick={() => setPage((current) => current - 1)}
                        className="rounded-lg border border-[#ddc9b5] px-4 py-2 text-xs font-bold disabled:opacity-40"
                    >
                        Sebelumnya
                    </button>

                    <button
                        type="button"
                        disabled={pagination.current_page >= pagination.last_page}
                        onClick={() => setPage((current) => current + 1)}
                        className="rounded-lg bg-[#543923] px-4 py-2 text-xs font-bold text-white disabled:opacity-40"
                    >
                        Berikutnya
                    </button>
                    </div>
                </div>
                )}
            </section>

            <footer className="border-t border-[#e4d3c2] pt-5 text-center text-xs text-[#a18b77]">
                LABOR_STATION Management System · Audit Log
            </footer>
            </div>
        </div>

        {/* DETAIL MODAL */}
        {selectedLog && (
            <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4"
            onClick={() => setSelectedLog(null)}
            >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="audit-detail-title"
                className="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-2xl sm:p-7"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9b795b]">
                    Activity Information
                    </p>
                    <h2
                    id="audit-detail-title"
                    className="mt-2 text-xl font-black"
                    >
                    Detail Aktivitas
                    </h2>
                </div>

                <button
                    type="button"
                    onClick={() => setSelectedLog(null)}
                    aria-label="Tutup detail"
                    className="rounded-lg bg-[#efe1d2] px-3 py-2 text-lg"
                >
                    ×
                </button>
                </div>

                <div className="mt-6 space-y-4">
                <div>
                    <p className="text-xs text-[#92775f]">Jenis Aktivitas</p>
                    <span
                    className={`mt-2 inline-block rounded-full px-3 py-1 text-xs font-bold ${actionColor(selectedLog.action)}`}
                    >
                    {actionLabel(selectedLog.action)}
                    </span>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                    <p className="text-xs text-[#92775f]">Nama Pengguna</p>
                    <p className="mt-1 break-words text-sm font-bold">
                        {selectedLog.user?.name ||
                        selectedLog.metadata?.name ||
                        "-"}
                    </p>
                    </div>

                    <div>
                    <p className="text-xs text-[#92775f]">Email</p>
                    <p className="mt-1 break-all text-sm font-bold">
                        {selectedLog.user?.email ||
                        selectedLog.metadata?.email ||
                        "-"}
                    </p>
                    </div>

                    <div>
                    <p className="text-xs text-[#92775f]">Role</p>
                    <p className="mt-1 text-sm font-bold">
                        {selectedLog.user?.role?.name ||
                        selectedLog.metadata?.role ||
                        "-"}
                    </p>
                    </div>

                    <div>
                    <p className="text-xs text-[#92775f]">Alamat IP</p>
                    <p className="mt-1 break-all text-sm font-bold">
                        {selectedLog.metadata?.ip_address || "-"}
                    </p>
                    </div>
                </div>

                <div>
                    <p className="text-xs text-[#92775f]">Waktu Aktivitas</p>
                    <p className="mt-1 text-sm font-bold">
                    {formatDate(selectedLog.created_at)}
                    </p>
                </div>

                <div>
                    <p className="text-xs text-[#92775f]">Jenis Entitas</p>
                    <p className="mt-1 text-sm font-bold">
                    {selectedLog.entity_type}
                    </p>
                </div>

                {selectedLog.metadata?.reason && (
                    <div>
                    <p className="text-xs text-[#92775f]">Keterangan</p>
                    <p className="mt-1 text-sm font-semibold">
                        {selectedLog.metadata.reason}
                    </p>
                    </div>
                )}

                <div>
                    <p className="text-xs text-[#92775f]">Perangkat / Browser</p>
                    <p className="mt-1 break-words text-xs leading-5 text-[#80664e]">
                    {selectedLog.metadata?.user_agent || "-"}
                    </p>
                </div>
                </div>

                <button
                type="button"
                onClick={() => setSelectedLog(null)}
                className="mt-7 w-full rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white hover:bg-[#69452d]"
                >
                Tutup Detail
                </button>
            </section>
            </div>
        )}
        </main>
    );
    }