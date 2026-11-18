    "use client";

    import { useCallback, useEffect, useState } from "react";

    const API_URL = (
    process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api"
    ).replace(/\/$/, "");

    type User = {
    id: number;
    name: string;
    email: string;
    role: string;
    };

    type DashboardData = {
    message: string;
    period: {
        filter: string;
        start_date: string;
        end_date: string;
    };
    sales: {
        offline: number;
        grabfood: number;
        gross_total: number;
    };
    customer_payment_confirmations: {
        offline: number;
        grabfood_platform: number;
        total: number;
    };
    grabfood_payout: {
        total_transactions: number;
        platform_deduction: number;
        net_payout_received: number;
    };
    expenses: {
        total: number;
    };
    transactions: {
        total: number;
        unpaid: number;
        order_statuses: Record<string, number>;
    };
    best_sellers: {
        product_name_snapshot: string;
        total_quantity: number;
        total_sales: number;
    }[];
    };

    type Period = "today" | "week" | "month";

    const navigation = [
    { label: "Dashboard", icon: "▦", href: "/dashboard", active: true },
    { label: "Transaksi", icon: "▤", href: "/dashboard/transactions" },
    { label: "Produk", icon: "◈", href: "/dashboard/products" },
    { label: "Inventori", icon: "▣", href: "/dashboard/inventory" },
    { label: "Pengeluaran", icon: "◉", href: "/dashboard/expenses" },
    { label: "Laporan", icon: "▥", href: "/dashboard/reports" },
    { label: "Audit Log", icon: "◷", href: "/dashboard/activity" },
    ];

    function formatRupiah(value: number) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(value || 0);
    }

    function formatDate(value: string) {
    if (!value) return "-";

    return new Date(value + "T00:00:00").toLocaleDateString("id-ID", {
        day: "numeric",
        month: "long",
        year: "numeric",
    });
    }

    function MetricCard({
    title,
    value,
    subtitle,
    icon,
    }: {
    title: string;
    value: string;
    subtitle: string;
    icon: string;
    }) {
    return (
        <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
        <div className="flex items-start justify-between gap-3">
            <div>
            <p className="text-sm font-medium text-[#8b735e]">{title}</p>
            <h3 className="mt-3 text-xl font-extrabold text-[#392619] sm:text-2xl">
                {value}
            </h3>
            <p className="mt-2 text-xs text-[#a18b77]">{subtitle}</p>
            </div>

            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#efe1d2] text-xl text-[#69452d]">
            {icon}
            </div>
        </div>
        </div>
    );
    }

    export default function OwnerDashboard() {
    const [user, setUser] = useState<User | null>(null);
    const [dashboard, setDashboard] = useState<DashboardData | null>(null);
    const [period, setPeriod] = useState<Period>("today");
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [loggingOut, setLoggingOut] = useState(false);

    useEffect(() => {
        const token = localStorage.getItem("labor_station_token");
        const savedUser = localStorage.getItem("labor_station_user");

        if (!token || !savedUser) {
        window.location.replace("/");
        return;
        }

        try {
        const parsedUser = JSON.parse(savedUser) as User;

        if (parsedUser.role !== "owner") {
            window.location.replace("/");
            return;
        }

        setUser(parsedUser);
        } catch {
        localStorage.removeItem("labor_station_user");
        window.location.replace("/");
        }
    }, []);

    const fetchDashboard = useCallback(async () => {
        const token = localStorage.getItem("labor_station_token");

        if (!token) {
        window.location.replace("/");
        return;
        }

        setLoading(true);
        setError("");

        try {
        const response = await fetch(
            `${API_URL}/owner/dashboard?period=${period}`,
            {
            method: "GET",
            headers: {
                Accept: "application/json",
                Authorization: `Bearer ${token}`,
            },
            cache: "no-store",
            }
        );

        if (response.status === 401 || response.status === 403) {
            localStorage.removeItem("labor_station_token");
            localStorage.removeItem("labor_station_user");
            window.location.replace("/");
            return;
        }

        const result = await response.json();

        if (!response.ok) {
            throw new Error(
            result.message || "Gagal mengambil data dashboard."
            );
        }

        setDashboard(result);
        } catch (err) {
        setError(
            err instanceof Error
            ? err.message
            : "Tidak dapat terhubung ke server."
        );
        } finally {
        setLoading(false);
        }
    }, [period]);

    useEffect(() => {
        if (user) {
        fetchDashboard();
        }
    }, [user, fetchDashboard]);

    async function handleLogout() {
        const token = localStorage.getItem("labor_station_token");

        if (!token) {
        localStorage.removeItem("labor_station_user");
        window.location.replace("/");
        return;
        }

        setLoggingOut(true);

        try {
        await fetch(`${API_URL}/logout`, {
            method: "POST",
            headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
            },
        });
        } catch {
        // Tetap bersihkan sesi lokal jika koneksi terputus.
        } finally {
        localStorage.removeItem("labor_station_token");
        localStorage.removeItem("labor_station_user");
        window.location.replace("/");
        }
    }

    const statuses = [
        { key: "NEW", label: "Pesanan Baru", color: "bg-blue-500" },
        { key: "PREPARING", label: "Sedang Disiapkan", color: "bg-amber-500" },
        { key: "READY", label: "Siap Diambil", color: "bg-emerald-500" },
        { key: "COMPLETED", label: "Selesai", color: "bg-[#8b674d]" },
        { key: "CANCELLED", label: "Dibatalkan", color: "bg-red-500" },
    ];

    return (
        <main className="min-h-screen bg-[#f5eee6] text-[#392619]">
        {/* MOBILE HEADER */}
        <header className="sticky top-0 z-40 flex h-[70px] items-center justify-between border-b border-[#e8d9ca] bg-[#fffaf4]/95 px-4 backdrop-blur lg:hidden">
            <button
            onClick={() => setSidebarOpen(true)}
            className="rounded-xl bg-[#efe1d2] px-3 py-2 text-xl text-[#543923]"
            aria-label="Buka menu"
            >
            ☰
            </button>

            <div className="text-center">
            <p className="text-sm font-black tracking-wide">LABOR_STATION</p>
            <p className="text-[10px] text-[#92775f]">Owner Dashboard</p>
            </div>

            <div className="h-9 w-9 rounded-full bg-[#69452d] text-center text-sm font-bold leading-9 text-white">
            {user?.name?.charAt(0).toUpperCase() || "O"}
            </div>
        </header>

        {/* MOBILE SIDEBAR OVERLAY */}
        {sidebarOpen && (
            <button
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
                    item.active
                        ? "bg-[#e6c5a3] font-bold text-[#392619] shadow-lg"
                        : "text-[#ead9c7] hover:bg-white/10 hover:text-white"
                    }`}
                >
                    <span className="w-6 text-center text-lg">
                    {item.icon}
                    </span>
                    {item.label}
                    {item.active && (
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
                Workspace / Overview
                </p>
                <h2 className="mt-1 text-lg font-extrabold">
                Owner Dashboard
                </h2>
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

            <div className="mx-auto max-w-[1500px] space-y-7 p-4 sm:p-6 lg:p-8 xl:p-10">
            {/* PAGE TITLE */}
            <section className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9b795b]">
                    Coffee Station Overview
                </p>

                <h1 className="mt-2 text-2xl font-black tracking-tight sm:text-3xl">
                    Good day, {user?.name?.split(" ")[0] || "Owner"}!
                </h1>

                <p className="mt-2 text-sm text-[#92775f]">
                    Berikut ringkasan performa bisnis coffee station kamu.
                </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                <select
                    value={period}
                    onChange={(event) =>
                    setPeriod(event.target.value as Period)
                    }
                    className="rounded-xl border border-[#ddc9b5] bg-[#fffaf4] px-4 py-3 text-sm font-semibold text-[#543923] outline-none focus:border-[#8b674d]"
                >
                    <option value="today">Hari Ini</option>
                    <option value="week">Minggu Ini</option>
                    <option value="month">Bulan Ini</option>
                </select>

                <button
                    onClick={fetchDashboard}
                    disabled={loading}
                    className="rounded-xl bg-[#543923] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#69452d] disabled:opacity-50"
                >
                    ↻ Refresh
                </button>
                </div>
            </section>

            {/* DATE RANGE */}
            {dashboard && (
                <div className="rounded-xl border border-[#eadccf] bg-[#fffaf4] px-4 py-3 text-xs text-[#92775f]">
                Periode laporan:{" "}
                <strong className="text-[#543923]">
                    {formatDate(dashboard.period.start_date)}
                </strong>{" "}
                sampai{" "}
                <strong className="text-[#543923]">
                    {formatDate(dashboard.period.end_date)}
                </strong>
                </div>
            )}

            {/* ERROR */}
            {error && (
                <div
                role="alert"
                className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 sm:flex-row sm:items-center sm:justify-between"
                >
                <p>{error}</p>
                <button
                    onClick={fetchDashboard}
                    className="font-bold underline"
                >
                    Coba lagi
                </button>
                </div>
            )}

            {/* LOADING */}
            {loading && !dashboard && (
                <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-10 text-center">
                <div className="mx-auto h-9 w-9 animate-spin rounded-full border-4 border-[#e8d9ca] border-t-[#69452d]" />
                <p className="mt-4 text-sm font-medium text-[#92775f]">
                    Memuat data dashboard...
                </p>
                </div>
            )}

            {/* DASHBOARD DATA */}
            {dashboard && (
                <>
                {/* SALES METRICS */}
                <section>
                    <div className="mb-4 flex items-center justify-between">
                    <h2 className="font-extrabold">Ringkasan Penjualan</h2>
                    <span className="text-xs text-[#92775f]">
                        Berdasarkan kanal penjualan
                    </span>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        title="Offline Sales"
                        value={formatRupiah(dashboard.sales.offline)}
                        subtitle="Penjualan dari kedai"
                        icon="☕"
                    />

                    <MetricCard
                        title="GrabFood Sales"
                        value={formatRupiah(dashboard.sales.grabfood)}
                        subtitle="Penjualan melalui GrabFood"
                        icon="🛵"
                    />

                    <MetricCard
                        title="Gross Sales"
                        value={formatRupiah(dashboard.sales.gross_total)}
                        subtitle="Total penjualan sebelum pengeluaran"
                        icon="◈"
                    />

                    <MetricCard
                        title="Total Transactions"
                        value={String(dashboard.transactions.total)}
                        subtitle={`${dashboard.transactions.unpaid} transaksi belum dibayar`}
                        icon="▤"
                    />
                    </div>
                </section>

                {/* FINANCIAL SUMMARY */}
                <section>
                    <h2 className="mb-4 font-extrabold">
                    Ringkasan Keuangan
                    </h2>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="rounded-2xl border border-[#e4d3c2] bg-gradient-to-br from-[#fffaf4] to-[#f0e1d1] p-5">
                        <p className="text-sm font-semibold text-[#80664e]">
                        Pembayaran Offline Terkonfirmasi
                        </p>
                        <p className="mt-3 text-xl font-black text-[#392619]">
                        {formatRupiah(
                            dashboard.customer_payment_confirmations.offline
                        )}
                        </p>
                        <p className="mt-2 text-xs text-[#92775f]">
                        Pembayaran pelanggan kedai
                        </p>
                    </div>

                    <div className="rounded-2xl border border-[#e4d3c2] bg-gradient-to-br from-[#fffaf4] to-[#f0e1d1] p-5">
                        <p className="text-sm font-semibold text-[#80664e]">
                        GrabFood Net Payout
                        </p>
                        <p className="mt-3 text-xl font-black text-[#392619]">
                        {formatRupiah(
                            dashboard.grabfood_payout.net_payout_received
                        )}
                        </p>
                        <p className="mt-2 text-xs text-[#92775f]">
                        Dana pencairan yang diterima
                        </p>
                    </div>

                    <div className="rounded-2xl border border-[#e4d3c2] bg-gradient-to-br from-[#fffaf4] to-[#f0e1d1] p-5">
                        <p className="text-sm font-semibold text-[#80664e]">
                        Total Pengeluaran
                        </p>
                        <p className="mt-3 text-xl font-black text-[#392619]">
                        {formatRupiah(dashboard.expenses.total)}
                        </p>
                        <p className="mt-2 text-xs text-[#92775f]">
                        Pengeluaran pada periode terpilih
                        </p>
                    </div>
                    </div>
                </section>

                {/* ORDER STATUS */}
                <section className="grid grid-cols-1 gap-5 xl:grid-cols-2">
                    <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 sm:p-6">
                    <h2 className="font-extrabold">Status Pesanan</h2>
                    <p className="mt-1 text-xs text-[#92775f]">
                        Distribusi status pesanan pada periode ini
                    </p>

                    <div className="mt-6 space-y-5">
                        {statuses.map((status) => {
                        const count =
                            dashboard.transactions.order_statuses?.[
                            status.key
                            ] || 0;

                        const total = dashboard.transactions.total;
                        const percentage =
                            total > 0 ? (count / total) * 100 : 0;

                        return (
                            <div key={status.key}>
                            <div className="mb-2 flex items-center justify-between text-sm">
                                <span className="font-medium text-[#694f3b]">
                                {status.label}
                                </span>
                                <span className="font-bold">{count}</span>
                            </div>

                            <div className="h-2 overflow-hidden rounded-full bg-[#eee3d8]">
                                <div
                                className={`h-full rounded-full ${status.color}`}
                                style={{ width: `${percentage}%` }}
                                />
                            </div>
                            </div>
                        );
                        })}
                    </div>
                    </div>

                    {/* BEST SELLERS */}
                    <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 sm:p-6">
                    <h2 className="font-extrabold">Produk Terlaris</h2>
                    <p className="mt-1 text-xs text-[#92775f]">
                        Lima produk dengan jumlah penjualan tertinggi
                    </p>

                    <div className="mt-5 space-y-3">
                        {dashboard.best_sellers.length === 0 ? (
                        <div className="rounded-xl bg-[#f5eee6] p-6 text-center">
                            <p className="text-sm text-[#92775f]">
                            Belum ada data penjualan produk.
                            </p>
                        </div>
                        ) : (
                        dashboard.best_sellers.map((product, index) => (
                            <div
                            key={`${product.product_name_snapshot}-${index}`}
                            className="flex items-center gap-3 rounded-xl bg-[#f8f0e7] p-3"
                            >
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e8d5c0] font-black text-[#69452d]">
                                {index + 1}
                            </div>

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-bold">
                                {product.product_name_snapshot}
                                </p>
                                <p className="mt-1 text-xs text-[#92775f]">
                                {product.total_quantity} terjual
                                </p>
                            </div>

                            <p className="text-right text-xs font-bold text-[#69452d]">
                                {formatRupiah(product.total_sales)}
                            </p>
                            </div>
                        ))
                        )}
                    </div>
                    </div>
                </section>

                {/* GRABFOOD PAYOUT DETAILS */}
                <section className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 sm:p-6">
                    <h2 className="font-extrabold">GrabFood Payout Details</h2>
                    <p className="mt-1 text-xs text-[#92775f]">
                    Ringkasan pencairan dana dari platform
                    </p>

                    <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <p className="text-xs text-[#92775f]">
                        Transaksi Dicairkan
                        </p>
                        <p className="mt-2 text-xl font-extrabold">
                        {dashboard.grabfood_payout.total_transactions}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs text-[#92775f]">
                        Potongan Platform
                        </p>
                        <p className="mt-2 text-lg font-bold text-red-700">
                        {formatRupiah(
                            dashboard.grabfood_payout.platform_deduction
                        )}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs text-[#92775f]">
                        Pencairan Bersih
                        </p>
                        <p className="mt-2 text-lg font-bold text-emerald-700">
                        {formatRupiah(
                            dashboard.grabfood_payout.net_payout_received
                        )}
                        </p>
                    </div>
                    </div>

                    <p className="mt-5 rounded-xl bg-[#f5eee6] p-3 text-xs leading-5 text-[#80664e]">
                    Catatan: Konfirmasi pembayaran pelanggan GrabFood berbeda
                    dengan pencairan dana ke rekening Owner. Pencairan dihitung
                    berdasarkan tanggal payout.
                    </p>
                </section>
                </>
            )}

            {/* FOOTER */}
            <footer className="border-t border-[#e4d3c2] pt-5 text-center text-xs text-[#a18b77]">
                LABOR_STATION Management System · Owner Workspace
            </footer>
            </div>
        </div>
        </main>
    );
    }