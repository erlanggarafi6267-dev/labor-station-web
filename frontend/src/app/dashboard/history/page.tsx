"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";

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

type TransactionItem = {
  id: number;
  product_name_snapshot?: string;
  product?: {
    name?: string;
  };
  item_type?: string;
  temperature?: string | null;
  unit_price: number | string;
  quantity: number;
  subtotal: number | string;
};

type Refund = {
  id: number;
  amount: number | string;
  method: string;
  reason: string;
  refunded_at: string;
  processor?: { name?: string };
};

type Payment = {
  id: number;
  method: string;
  amount: number | string;
  reference_number?: string | null;
  paid_at?: string | null;
};

type Transaction = {
  id: number;
  invoice_number: string;
  customer_name?: string | null;
  sales_channel: string;
  order_status: string;
  payment_status: string;
  subtotal: number | string;
  discount?: number | string;
  total: number | string;
  notes?: string | null;
  created_at: string;
  items?: TransactionItem[];
  payment?: Payment | null;
  refund?: Refund | null;
  user?: {
    name?: string;
  };
};

type Pagination = {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
};

type PaymentMethod = "CASH" | "QRIS" | "TRANSFER";

const EMPTY_PAGINATION: Pagination = {
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 15,
};

function formatRupiah(value: number | string | undefined) {
  const amount = Number(value || 0);

  return new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number.isFinite(amount) ? amount : 0);
}

function formatDate(value?: string | null) {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

function statusLabel(status?: string) {
  const labels: Record<string, string> = {
    PAID: "Lunas",
    UNPAID: "Belum Dibayar",
    REFUNDED: "Direfund",
    PENDING: "Menunggu",

    NEW: "Pesanan Baru",
    PREPARING: "Sedang Disiapkan",
    READY: "Siap Diambil",
    COMPLETED: "Selesai",
    CANCELLED: "Dibatalkan",

    OFFLINE: "Offline",
    GRABFOOD: "GrabFood",
  };

  return labels[status || ""] || status || "-";
}

function statusStyle(status?: string) {
  switch (status) {
    case "PAID":
    case "COMPLETED":
      return "bg-green-100 text-green-700";

    case "UNPAID":
    case "PENDING":
    case "NEW":
      return "bg-amber-100 text-amber-700";

    case "CANCELLED":
      return "bg-red-100 text-red-700";

    case "REFUNDED":
      return "bg-orange-100 text-orange-800";

    case "PREPARING":
      return "bg-blue-100 text-blue-700";

    case "READY":
      return "bg-purple-100 text-purple-700";

    default:
      return "bg-stone-100 text-stone-600";
  }
}

async function readResponse(response: Response) {
  const result = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(
      result.message || `Permintaan gagal (${response.status}).`
    );
  }

  return result;
}

export default function TransactionHistoryPage() {
  const router = useRouter();
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [pagination, setPagination] =
    useState<Pagination>(EMPTY_PAGINATION);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [paymentFilter, setPaymentFilter] = useState("ALL");
  const [channelFilter, setChannelFilter] = useState("ALL");
  const [currentPage, setCurrentPage] = useState(1);

    const [selectedTransaction, setSelectedTransaction] = useState<Transaction | null>(null);

    const [detailLoading, setDetailLoading] = useState(false);
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("CASH");
    const [referenceNumber, setReferenceNumber] = useState("");
    const [paymentLoading, setPaymentLoading] = useState(false);
    const [actionMessage, setActionMessage] = useState("");
    const [orderStatusLoading, setOrderStatusLoading] = useState(false);
    const [refundOpen, setRefundOpen] = useState(false);
    const [refundReason, setRefundReason] = useState("");
    const [refundLoading, setRefundLoading] = useState(false);
    const [canRefund, setCanRefund] = useState(false);

    const getHeaders = useCallback(() => {
    return {
        Accept: "application/json",
        "Content-Type": "application/json",
    };
    }, []);

  const fetchTransactions = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const params = new URLSearchParams({
        page: String(currentPage),
      });

      if (search.trim()) {
        params.set("search", search.trim());
      }

      if (paymentFilter !== "ALL") {
        params.set("payment_status", paymentFilter);
      }

      if (channelFilter !== "ALL") {
        params.set("sales_channel", channelFilter);
      }

      const response = await fetch(
        `${API_URL}/transactions?${params.toString()}`,
        {
          method: "GET",
          credentials: "include",
          headers: getHeaders(),
          cache: "no-store",
        }
      );

      const result = await readResponse(response);
      const payload = result.data;

      setTransactions(
        Array.isArray(payload?.data) ? payload.data : []
      );

      setPagination({
        current_page: Number(payload?.current_page || 1),
        last_page: Number(payload?.last_page || 1),
        total: Number(payload?.total || 0),
        per_page: Number(payload?.per_page || 15),
      });
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal mengambil data transaksi."
      );
      setTransactions([]);
    } finally {
      setLoading(false);
    }
  }, [
    channelFilter,
    currentPage,
    getHeaders,
    paymentFilter,
    search,
  ]);


  useEffect(() => {
    queueMicrotask(() => {
      void fetchTransactions();
    });
  }, [fetchTransactions]);

  useEffect(() => {
    let active = true;
    fetch(`${API_URL}/me`, { credentials: "include", headers: { Accept: "application/json" }, cache: "no-store" })
      .then((response) => response.ok ? response.json() : null)
      .then((result) => {
        if (!active || !result?.user) return;
        const permissions: string[] = result.user.permissions || [];
        setCanRefund(result.user.role === "owner" || permissions.includes("transaction.refund"));
      })
      .catch(() => { if (active) setCanRefund(false); });
    return () => { active = false; };
  }, []);


  const paidCount = useMemo(
    () =>
      transactions.filter((item) => item.payment_status === "PAID")
        .length,
    [transactions]
  );

  const unpaidCount = useMemo(
    () =>
      transactions.filter((item) => item.payment_status === "UNPAID")
        .length,
    [transactions]
  );

  const visibleTotal = useMemo(
    () =>
      transactions.reduce(
        (total, item) => total + Number(item.total || 0),
        0
      ),
    [transactions]
  );

  async function openDetail(transaction: Transaction) {
    setSelectedTransaction(transaction);
    setActionMessage("");
    setDetailLoading(true);

    try {
      const response = await fetch(
        `${API_URL}/transactions/${transaction.id}`,
        {
          method: "GET",
          headers: getHeaders(),
          credentials: "include",
          cache: "no-store",
        }
      );

      const result = await readResponse(response);
      setSelectedTransaction(result.data);
    } catch (err) {
      setActionMessage(
        err instanceof Error
          ? err.message
          : "Gagal mengambil detail transaksi."
      );
    } finally {
      setDetailLoading(false);
    }
  }

async function submitPayment() {
    if (!selectedTransaction) return;

    if (
        selectedTransaction.payment_status === "PAID" ||
        selectedTransaction.order_status === "CANCELLED"
    ) {
        return;
    }

    if (
        selectedTransaction.sales_channel === "GRABFOOD" &&
        paymentMethod !== "CASH"
    ) {
        setActionMessage(
            "Transaksi GrabFood harus menggunakan metode pembayaran GrabFood."
        );
        return;
    }

    setPaymentLoading(true);
    setActionMessage("");

    try {
        // Ambil CSRF token sebelum mengirim request POST.
        const csrfToken = await getCsrfToken();

        const response = await fetch(
            `${API_URL}/transactions/${selectedTransaction.id}/payment`,
            {
                method: "POST",
                credentials: "include",
                headers: {
                    ...getHeaders(),
                    "X-XSRF-TOKEN": csrfToken,
                },
                body: JSON.stringify({
                    method:
                        selectedTransaction.sales_channel === "GRABFOOD"
                            ? "GRAB_PLATFORM"
                            : paymentMethod,
                    reference_number: referenceNumber.trim() || null,
                }),
            }
        );

        const result = await readResponse(response);

        setPaymentOpen(false);
        setReferenceNumber("");

        setActionMessage(
            result.message || "Pembayaran berhasil dikonfirmasi."
        );

        await fetchTransactions();

        // Ambil detail transaksi terbaru.
        const detailResponse = await fetch(
            `${API_URL}/transactions/${selectedTransaction.id}`,
            {
                method: "GET",
                credentials: "include",
                headers: getHeaders(),
                cache: "no-store",
            }
        );

        const detailResult = await readResponse(detailResponse);

        setSelectedTransaction(detailResult.data);
    } catch (err) {
        setActionMessage(
            err instanceof Error
                ? err.message
                : "Pembayaran gagal diproses."
        );
    } finally {
        setPaymentLoading(false);
    }
}

async function submitRefund() {
    if (!selectedTransaction || refundLoading) return;

    const reason = refundReason.trim();
    if (reason.length < 3 || reason.length > 1000) {
      setActionMessage("Alasan refund wajib diisi (3–1000 karakter).");
      return;
    }

    setRefundLoading(true);
    setActionMessage("");

    try {
      const csrfToken = await getCsrfToken();
      const response = await fetch(
        API_URL + "/transactions/" + selectedTransaction.id + "/refund",
        {
          method: "POST",
          credentials: "include",
          headers: { ...getHeaders(), "X-XSRF-TOKEN": csrfToken },
          body: JSON.stringify({ reason }),
        }
      );
      const result = await readResponse(response);
      setRefundOpen(false);
      setRefundReason("");
      setActionMessage(result.message || "Refund tunai berhasil dicatat.");
      await fetchTransactions();

      const detailResponse = await fetch(
        API_URL + "/transactions/" + selectedTransaction.id,
        { credentials: "include", headers: getHeaders(), cache: "no-store" }
      );
      const detailResult = await readResponse(detailResponse);
      setSelectedTransaction(detailResult.data);
    } catch (err) {
      setActionMessage(err instanceof Error ? err.message : "Refund gagal diproses.");
    } finally {
      setRefundLoading(false);
    }
  }

async function updateOrderStatus(
    newStatus: "PREPARING" | "READY" | "COMPLETED" | "CANCELLED"
) {
    if (!selectedTransaction || orderStatusLoading) return;

    if (
        newStatus === "CANCELLED" &&
        selectedTransaction.payment_status === "PAID"
    ) {
        setActionMessage(
            "Transaksi yang sudah lunas tidak dapat dibatalkan."
        );
        return;
    }

    if (newStatus === "CANCELLED") {
        const confirmed = window.confirm(
            "Yakin ingin membatalkan pesanan ini? Tindakan ini tidak dapat dibatalkan."
        );

        if (!confirmed) return;
    }

    setOrderStatusLoading(true);
    setActionMessage("");

    try {
        // Ambil CSRF token sebelum mengirim request PATCH.
        const csrfToken = await getCsrfToken();

        const response = await fetch(
            `${API_URL}/transactions/${selectedTransaction.id}/status`,
            {
                method: "PATCH",
                credentials: "include",
                headers: {
                    ...getHeaders(),
                    "X-XSRF-TOKEN": csrfToken,
                },
                body: JSON.stringify({
                    order_status: newStatus,
                }),
            }
        );

        const result = await readResponse(response);

        setActionMessage(
            result.message || "Status pesanan berhasil diperbarui."
        );

        await fetchTransactions();

        // Ambil detail transaksi terbaru.
        const detailResponse = await fetch(
            `${API_URL}/transactions/${selectedTransaction.id}`,
            {
                method: "GET",
                credentials: "include",
                headers: getHeaders(),
                cache: "no-store",
            }
        );

        const detailResult = await readResponse(detailResponse);

        setSelectedTransaction(detailResult.data);
    } catch (err) {
        setActionMessage(
            err instanceof Error
                ? err.message
                : "Gagal memperbarui status pesanan."
        );
    } finally {
        setOrderStatusLoading(false);
    }
}

  function handleSearch(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setCurrentPage(1);
    setSearch(searchInput);
  }

function closeDetail() {
    if (paymentLoading || orderStatusLoading) return;

    setSelectedTransaction(null);
    setPaymentOpen(false);
    setActionMessage("");
}

  return (
    <main className="min-h-screen bg-[#f5eee6] p-4 text-[#392619] sm:p-6 lg:p-8">
      <div className="mx-auto max-w-7xl space-y-6">

        {/* HEADER */}
        <section className="space-y-5">
          <div className="flex items-center justify-between gap-4">
            <button
              type="button"
            onClick={() => router.push("/dashboard")}
              className="inline-flex items-center gap-2 rounded-xl border border-[#eadccf] bg-[#fffaf4] px-4 py-2.5 text-sm font-semibold text-[#543923] transition hover:bg-[#f1e6da]"
            >
              <span>←</span>
              Kembali ke Dashboard
            </button>

            <button
              type="button"
              onClick={() => void fetchTransactions()}
              disabled={loading}
              className="rounded-xl border border-[#eadccf] bg-[#fffaf4] px-4 py-3 text-sm font-semibold text-[#543923] transition hover:bg-[#f7eee4] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? "Memuat..." : "↻ Muat Ulang"}
            </button>
          </div>

          <div>
            <p className="text-sm font-medium text-[#92775f]">
              Workspace / Transaksi / Riwayat
            </p>

            <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">
              Riwayat Transaksi
            </h1>

            <p className="mt-1 text-sm text-[#92775f]">
              Pantau transaksi dan kelola pembayaran.
            </p>
          </div>
        </section>

        {/* RINGKASAN */}
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <SummaryCard
            title="Total Transaksi"
            value={String(pagination.total)}
            description="Transaksi ditemukan"
            icon="▤"
          />

          <SummaryCard
            title="Transaksi Lunas"
            value={String(paidCount)}
            description="Pada halaman ini"
            icon="✓"
          />

          <SummaryCard
            title="Belum Dibayar"
            value={String(unpaidCount)}
            description="Pada halaman ini"
            icon="◷"
          />

          <SummaryCard
            title="Nilai Halaman"
            value={formatRupiah(visibleTotal)}
            description="Total transaksi halaman ini"
            icon="Rp"
          />
        </section>

        {/* DAFTAR TRANSAKSI */}
        <section className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-4 shadow-sm sm:p-5">
          <div className="mb-4">
            <h2 className="text-lg font-bold">
              Daftar Transaksi
            </h2>

            <p className="mt-1 text-sm text-[#92775f]">
              Cari dan pilih transaksi untuk melihat detailnya.
            </p>
          </div>

          <form
            onSubmit={handleSearch}
            className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_180px_180px_auto]"
          >
            <input
              type="search"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Cari nomor invoice atau pelanggan..."
              className="min-w-0 rounded-xl border border-[#eadccf] bg-white px-4 py-3 text-sm outline-none transition placeholder:text-[#b29b85] focus:border-[#92704e] focus:ring-2 focus:ring-[#92704e]/10"
            />

            <select
              value={paymentFilter}
              onChange={(event) => {
                setPaymentFilter(event.target.value);
                setCurrentPage(1);
              }}
              className="rounded-xl border border-[#eadccf] bg-white px-3 py-3 text-sm outline-none focus:border-[#92704e]"
            >
              <option value="ALL">Semua Pembayaran</option>
              <option value="PAID">Lunas</option>
              <option value="UNPAID">Belum Dibayar</option>
              <option value="REFUNDED">Direfund</option>
            </select>

            <select
              value={channelFilter}
              onChange={(event) => {
                setChannelFilter(event.target.value);
                setCurrentPage(1);
              }}
              className="rounded-xl border border-[#eadccf] bg-white px-3 py-3 text-sm outline-none focus:border-[#92704e]"
            >
              <option value="ALL">Semua Channel</option>
              <option value="OFFLINE">Offline</option>
              <option value="GRABFOOD">GrabFood</option>
            </select>

            <button
              type="submit"
              className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#69452d]"
            >
              Cari
            </button>
          </form>

          {error && (
            <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
              <p className="font-semibold">
                Gagal memuat transaksi
              </p>
              <p className="mt-1">{error}</p>
              <p className="mt-2 text-xs">
                Periksa koneksi backend, token login, dan izin akses
                transaction.view.
              </p>
            </div>
          )}

          <div className="mt-5 overflow-x-auto">
            <table className="w-full min-w-[1000px] border-collapse text-left">
              <thead>
                <tr className="border-b border-[#eadccf] text-xs uppercase tracking-wider text-[#92775f]">
                  <th className="px-3 py-4 font-semibold">Invoice</th>
                  <th className="px-3 py-4 font-semibold">Pelanggan</th>
                  <th className="px-3 py-4 font-semibold">Tanggal</th>
                  <th className="px-3 py-4 font-semibold">Channel</th>
                  <th className="px-3 py-4 text-right font-semibold">
                    Total
                  </th>
                  <th className="px-3 py-4 font-semibold">
                    Pembayaran
                  </th>
                  <th className="px-3 py-4 font-semibold">
                        Status Pesanan
                    </th>
                  <th className="px-3 py-4 text-center font-semibold">
                    Aksi
                  </th>
                </tr>
              </thead>

              <tbody>
                {loading ? (
                  <tr>
                    <td
                      colSpan={8}
                      className="px-3 py-12 text-center text-sm text-[#92775f]"
                    >
                      Memuat data transaksi...
                    </td>
                  </tr>
                ) : transactions.length === 0 ? (
                  <tr>
                    <td
                      colSpan={8}
                      className="px-3 py-14 text-center"
                    >
                      <div className="text-3xl">▤</div>
                      <p className="mt-3 font-semibold">
                        Belum ada transaksi
                      </p>
                      <p className="mt-1 text-sm text-[#92775f]">
                        Transaksi yang tercatat akan muncul di sini.
                      </p>
                    </td>
                  </tr>
                ) : (
                  transactions.map((transaction) => (
                    <tr
                      key={transaction.id}
                      className="border-b border-[#f0e6dc] text-sm transition hover:bg-[#faf3eb]"
                    >
                      <td className="px-3 py-4">
                        <p className="font-semibold text-[#543923]">
                          {transaction.invoice_number}
                        </p>
                      </td>

                      <td className="px-3 py-4">
                        <p className="font-medium">
                          {transaction.customer_name || "Pelanggan Umum"}
                        </p>
                        <p className="mt-1 text-xs text-[#92775f]">
                          {transaction.user?.name || "Kasir"}
                        </p>
                      </td>

                      <td className="px-3 py-4 text-[#76604c]">
                        {formatDate(transaction.created_at)}
                      </td>

                      <td className="px-3 py-4">
                        <span className="rounded-lg bg-[#f1e6da] px-2.5 py-1.5 text-xs font-semibold text-[#69452d]">
                          {statusLabel(transaction.sales_channel)}
                        </span>
                      </td>

                      <td className="px-3 py-4 text-right font-semibold">
                        {formatRupiah(transaction.total)}
                      </td>

                    <td className="px-3 py-4">
                    <span
                        className={`rounded-full px-3 py-1.5 text-xs font-semibold ${statusStyle(
                        transaction.payment_status
                        )}`}
                    >
                        {statusLabel(transaction.payment_status)}
                    </span>
                    </td>

                    <td className="px-3 py-4">
                    <span
                        className={`inline-flex whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ${statusStyle(
                        transaction.order_status
                        )}`}
                    >
                        {statusLabel(transaction.order_status)}
                    </span>
                    </td>

                      <td className="px-3 py-4 text-center">
                        <button
                          type="button"
                          onClick={() => void openDetail(transaction)}
                          className="rounded-lg border border-[#ddc8b5] px-3 py-2 text-xs font-semibold text-[#543923] transition hover:bg-[#f1e6da]"
                        >
                          Detail
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* PAGINATION */}
          <div className="mt-5 flex flex-col justify-between gap-3 border-t border-[#eadccf] pt-4 sm:flex-row sm:items-center">
            <p className="text-sm text-[#92775f]">
              Halaman {pagination.current_page} dari {pagination.last_page}
              {" · "}
              {pagination.total} transaksi
            </p>

            <div className="flex gap-2">
              <button
                type="button"
                disabled={loading || currentPage <= 1}
                onClick={() =>
                  setCurrentPage((page) => Math.max(1, page - 1))
                }
                className="rounded-lg border border-[#eadccf] px-4 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-40"
              >
                Sebelumnya
              </button>

              <button
                type="button"
                disabled={
                  loading || currentPage >= pagination.last_page
                }
                onClick={() =>
                  setCurrentPage((page) =>
                    Math.min(pagination.last_page, page + 1)
                  )
                }
                className="rounded-lg border border-[#eadccf] px-4 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-40"
              >
                Berikutnya
              </button>
            </div>
          </div>
        </section>
      </div>

      {/* MODAL DETAIL TRANSAKSI */}
      {selectedTransaction && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 sm:p-5"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closeDetail();
          }}
        >
          <section className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-[#fffaf4] shadow-2xl">
            <header className="sticky top-0 flex items-center justify-between border-b border-[#eadccf] bg-[#fffaf4] px-5 py-4">
              <div>
                <h2 className="text-lg font-bold">
                  Detail Transaksi
                </h2>
                <p className="mt-1 text-xs text-[#92775f]">
                  {selectedTransaction.invoice_number}
                </p>
              </div>

              <button
                type="button"
                onClick={closeDetail}
                disabled={paymentLoading || orderStatusLoading}
                aria-label="Tutup detail"
                className="rounded-lg px-3 py-2 text-xl text-[#92775f] hover:bg-[#f1e6da] disabled:opacity-40"
              >
                ×
              </button>
            </header>

            <div className="space-y-5 p-5">
              {detailLoading ? (
                <p className="py-10 text-center text-sm text-[#92775f]">
                  Memuat detail transaksi...
                </p>
              ) : (
                <>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <InfoBox
                      label="Pelanggan"
                      value={
                        selectedTransaction.customer_name ||
                        "Pelanggan Umum"
                      }
                    />

                    <InfoBox
                      label="Tanggal Transaksi"
                      value={formatDate(selectedTransaction.created_at)}
                    />

                    <InfoBox
                      label="Channel"
                      value={statusLabel(selectedTransaction.sales_channel)}
                    />

                    <InfoBox
                      label="Kasir"
                      value={selectedTransaction.user?.name || "-"}
                    />
                  </div>

                  {/* STATUS PESANAN */}
                <div className="rounded-xl border border-[#eadccf] bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                    <p className="text-sm font-bold text-[#543923]">
                        Status Pesanan
                    </p>

                    <p className="mt-1 text-xs text-[#92775f]">
                        Pantau dan perbarui proses pesanan.
                    </p>
                    </div>

                    <span
                    className={`rounded-full px-3 py-1.5 text-xs font-bold ${statusStyle(
                        selectedTransaction.order_status
                    )}`}
                    >
                    {statusLabel(selectedTransaction.order_status)}
                    </span>
                    </div>

                    {selectedTransaction.order_status === "NEW" && (
                        <button
                        type="button"
                        disabled={orderStatusLoading}
                        onClick={() => void updateOrderStatus("PREPARING")}
                        className="mt-4 w-full rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#69452d] disabled:cursor-not-allowed disabled:opacity-50"
                        >
                        {orderStatusLoading ? "Memproses..." : "Mulai Proses Pesanan"}
                        </button>
                    )}

                    {selectedTransaction.order_status === "NEW" &&
                    selectedTransaction.payment_status === "UNPAID" && (
                        <button
                        type="button"
                        disabled={orderStatusLoading}
                        onClick={() => void updateOrderStatus("CANCELLED")}
                        className="mt-3 w-full rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                        {orderStatusLoading
                            ? "Memproses..."
                            : "Batalkan Pesanan"}
                        </button>
                    )}

                    {selectedTransaction.order_status === "PREPARING" && (
                        <button
                        type="button"
                        disabled={orderStatusLoading}
                        onClick={() => void updateOrderStatus("READY")}
                        className="mt-4 w-full rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#69452d] disabled:cursor-not-allowed disabled:opacity-50"
                        >
                        {orderStatusLoading ? "Memproses..." : "Tandai Siap Diambil"}
                        </button>
                    )}

                    {selectedTransaction.order_status === "READY" && (
                        <button
                        type="button"
                        disabled={orderStatusLoading}
                        onClick={() => void updateOrderStatus("COMPLETED")}
                        className="mt-4 w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                        {orderStatusLoading ? "Memproses..." : "Selesaikan Pesanan"}
                        </button>
                    )}

                    {selectedTransaction.order_status === "COMPLETED" && (
                        <p className="mt-4 rounded-lg bg-green-50 p-3 text-center text-sm font-semibold text-green-700">
                        Pesanan telah selesai.
                        </p>
                    )}

                    {selectedTransaction.order_status === "CANCELLED" && (
                        <p className="mt-4 rounded-lg bg-red-50 p-3 text-center text-sm font-semibold text-red-700">
                        Pesanan telah dibatalkan.
                        </p>
                    )}
                    </div>

                  {/* RINCIAN PRODUK */}
                  <div>
                    <h3 className="mb-3 font-bold">
                      Rincian Produk
                    </h3>

                    <div className="overflow-x-auto rounded-xl border border-[#eadccf]">
                      <table className="w-full min-w-[450px] text-sm">
                        <thead className="bg-[#f5eee6] text-xs text-[#92775f]">
                          <tr>
                            <th className="px-3 py-3 text-left">
                              Produk
                            </th>
                            <th className="px-3 py-3 text-center">
                              Qty
                            </th>
                            <th className="px-3 py-3 text-right">
                              Harga
                            </th>
                            <th className="px-3 py-3 text-right">
                              Subtotal
                            </th>
                          </tr>
                        </thead>

                        <tbody>
                          {selectedTransaction.items?.length ? (
                            selectedTransaction.items.map((item) => (
                              <tr
                                key={item.id}
                                className="border-t border-[#eadccf]"
                              >
                                <td className="px-3 py-3">
                                  <p className="font-medium">
                                    {item.product_name_snapshot ||
                                      item.product?.name ||
                                      "Produk"}
                                  </p>

                                  {item.temperature && (
                                    <p className="mt-1 text-xs text-[#92775f]">
                                      {item.temperature}
                                    </p>
                                  )}
                                </td>

                                <td className="px-3 py-3 text-center">
                                  {item.quantity}
                                </td>

                                <td className="px-3 py-3 text-right">
                                  {formatRupiah(item.unit_price)}
                                </td>

                                <td className="px-3 py-3 text-right font-semibold">
                                  {formatRupiah(item.subtotal)}
                                </td>
                              </tr>
                            ))
                          ) : (
                            <tr>
                              <td
                                colSpan={4}
                                className="px-3 py-6 text-center text-[#92775f]"
                              >
                                Tidak ada rincian produk.
                              </td>
                            </tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {/* TOTAL */}
                  <div className="space-y-2 rounded-xl bg-[#f5eee6] p-4">
                    <TotalRow
                      label="Subtotal"
                      value={formatRupiah(selectedTransaction.subtotal)}
                    />

                    <TotalRow
                      label="Diskon"
                      value={formatRupiah(selectedTransaction.discount)}
                    />

                    <div className="border-t border-[#ddc8b5] pt-3">
                      <TotalRow
                        label="Total Pembayaran"
                        value={formatRupiah(selectedTransaction.total)}
                        strong
                      />
                    </div>
                  </div>

                  {/* STATUS PEMBAYARAN */}
                  <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="text-xs text-[#92775f]">
                        Status Pembayaran
                      </p>

                      <span
                        className={`mt-2 inline-flex rounded-full px-3 py-1.5 text-xs font-bold ${statusStyle(
                          selectedTransaction.payment_status
                        )}`}
                      >
                        {statusLabel(selectedTransaction.payment_status)}
                      </span>
                    </div>

                    {selectedTransaction.payment && (
                      <div className="text-right">
                        <p className="text-xs text-[#92775f]">
                          Metode Pembayaran
                        </p>
                        <p className="mt-1 font-semibold">
                          {selectedTransaction.payment.method}
                        </p>
                      </div>
                    )}
                  </div>

                  {selectedTransaction.refund && (
                    <div className="rounded-xl border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900">
                      <p className="font-bold">Refund tunai tercatat</p>
                      <p className="mt-1">Nominal: {formatRupiah(selectedTransaction.refund.amount)}</p>
                      <p className="mt-1">Waktu: {formatDate(selectedTransaction.refund.refunded_at)}</p>
                      <p className="mt-1">Diproses oleh: {selectedTransaction.refund.processor?.name || "-"}</p>
                      <p className="mt-1">Alasan: {selectedTransaction.refund.reason}</p>
                    </div>
                  )}

                  {selectedTransaction.payment && (
                    <div className="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                      <p className="font-bold">
                        Pembayaran telah tercatat
                      </p>

                      <p className="mt-1">
                        Waktu:{" "}
                        {formatDate(selectedTransaction.payment.paid_at)}
                      </p>

                      {selectedTransaction.payment.reference_number && (
                        <p className="mt-1">
                          Referensi:{" "}
                          {selectedTransaction.payment.reference_number}
                        </p>
                      )}
                    </div>
                  )}

                  {selectedTransaction.notes && (
                    <div>
                      <p className="text-xs font-semibold text-[#92775f]">
                        Catatan
                      </p>
                      <p className="mt-1 text-sm">
                        {selectedTransaction.notes}
                      </p>
                    </div>
                  )}

                  {actionMessage && (
                    <div className="rounded-xl border border-[#eadccf] bg-white p-3 text-sm text-[#69452d]">
                      {actionMessage}
                    </div>
                  )}

                  {canRefund &&
                    selectedTransaction.sales_channel === "OFFLINE" &&
                    selectedTransaction.payment_status === "PAID" &&
                    !selectedTransaction.refund && (
                      <button
                        type="button"
                        onClick={() => {
                          setRefundReason("");
                          setActionMessage("");
                          setRefundOpen(true);
                        }}
                        className="w-full rounded-xl border border-orange-300 bg-orange-50 px-5 py-3.5 text-sm font-bold text-orange-800 transition hover:bg-orange-100"
                      >
                        Proses Refund Tunai
                      </button>
                    )}

                  {selectedTransaction.payment_status === "UNPAID" &&
                    selectedTransaction.order_status !== "CANCELLED" && (
                      <button
                        type="button"
                        onClick={() => {
                          setActionMessage("");
                          setPaymentOpen(true);
                        }}
                        className="w-full rounded-xl bg-[#543923] px-5 py-3.5 text-sm font-bold text-white transition hover:bg-[#69452d]"
                      >
                        Konfirmasi Pembayaran
                      </button>
                    )}

                  {selectedTransaction.order_status === "CANCELLED" && (
                    <p className="rounded-xl bg-red-50 p-3 text-center text-sm font-medium text-red-700">
                      Transaksi dibatalkan dan tidak dapat dibayar.
                    </p>
                  )}
                </>
              )}
            </div>
          </section>
        </div>
      )}

      {/* MODAL REFUND TUNAI */}
      {refundOpen && selectedTransaction && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 p-3">
          <section className="w-full max-w-md rounded-2xl bg-[#fffaf4] p-5 shadow-2xl">
            <h2 className="text-xl font-bold text-[#543923]">Konfirmasi Refund Tunai</h2>
            <p className="mt-1 text-sm text-[#92775f]">{selectedTransaction.invoice_number}</p>
            <div className="mt-4 rounded-xl border border-orange-200 bg-orange-50 p-4">
              <p className="text-sm text-orange-800">Nominal yang dikembalikan</p>
              <p className="mt-1 text-2xl font-bold text-orange-900">{formatRupiah(selectedTransaction.payment?.amount ?? selectedTransaction.total)}</p>
              <p className="mt-1 text-xs text-orange-800">Metode pengembalian selalu CASH. Pembayaran asli tetap tersimpan.</p>
            </div>
            <label htmlFor="refund-reason" className="mt-5 mb-2 block text-sm font-semibold">Alasan refund</label>
            <textarea
              id="refund-reason"
              value={refundReason}
              onChange={(event) => setRefundReason(event.target.value)}
              maxLength={1000}
              rows={4}
              placeholder="Tuliskan alasan refund secara jelas..."
              className="w-full resize-y rounded-xl border border-[#eadccf] bg-white px-3 py-3 text-sm outline-none focus:border-[#92704e]"
            />
            <p className="mt-1 text-right text-xs text-[#92775f]">{refundReason.trim().length}/1000</p>
            <div className="mt-5 flex gap-3">
              <button type="button" disabled={refundLoading} onClick={() => setRefundOpen(false)} className="flex-1 rounded-xl border border-[#eadccf] px-4 py-3 text-sm font-semibold text-[#543923] disabled:opacity-50">Batal</button>
              <button type="button" disabled={refundLoading || refundReason.trim().length < 3} onClick={() => void submitRefund()} className="flex-1 rounded-xl bg-orange-700 px-4 py-3 text-sm font-bold text-white transition hover:bg-orange-800 disabled:cursor-not-allowed disabled:opacity-50">{refundLoading ? "Memproses..." : "Konfirmasi Refund"}</button>
            </div>
          </section>
        </div>
      )}

      {/* MODAL KONFIRMASI PEMBAYARAN */}
      {paymentOpen && selectedTransaction && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-3">
          <section className="w-full max-w-md rounded-2xl bg-[#fffaf4] p-5 shadow-2xl">
            <h2 className="text-xl font-bold">
              Konfirmasi Pembayaran
            </h2>

            <p className="mt-1 text-sm text-[#92775f]">
              {selectedTransaction.invoice_number}
            </p>

            <div className="mt-4 rounded-xl bg-[#f5eee6] p-4">
              <p className="text-sm text-[#92775f]">
                Total yang harus dibayar
              </p>

              <p className="mt-1 text-2xl font-bold text-[#543923]">
                {formatRupiah(selectedTransaction.total)}
              </p>
            </div>

            <div className="mt-5">
              <label className="mb-2 block text-sm font-semibold">
                Metode Pembayaran
              </label>

              {selectedTransaction.sales_channel === "GRABFOOD" ? (
                <div className="rounded-xl border border-[#eadccf] bg-[#f5eee6] p-3 text-sm font-semibold">
                  GrabFood Platform
                </div>
              ) : (
                <select
                  value={paymentMethod}
                  onChange={(event) =>
                    setPaymentMethod(
                      event.target.value as PaymentMethod
                    )
                  }
                  className="w-full rounded-xl border border-[#eadccf] bg-white px-3 py-3 text-sm outline-none focus:border-[#92704e]"
                >
                  <option value="CASH">Cash / Tunai</option>
                  <option value="QRIS">QRIS</option>
                  <option value="TRANSFER">Transfer Bank</option>
                </select>
              )}
            </div>

            <div className="mt-4">
              <label className="mb-2 block text-sm font-semibold">
                Nomor Referensi
                <span className="ml-1 font-normal text-[#92775f]">
                  (Opsional)
                </span>
              </label>

              <input
                type="text"
                value={referenceNumber}
                onChange={(event) =>
                  setReferenceNumber(event.target.value)
                }
                placeholder="Masukkan nomor referensi"
                className="w-full rounded-xl border border-[#eadccf] bg-white px-3 py-3 text-sm outline-none focus:border-[#92704e]"
              />
            </div>

            <div className="mt-6 flex gap-3">
              <button
                type="button"
                disabled={paymentLoading}
                onClick={() => setPaymentOpen(false)}
                className="flex-1 rounded-xl border border-[#eadccf] px-4 py-3 text-sm font-semibold text-[#543923] disabled:opacity-50"
              >
                Batal
              </button>

              <button
                type="button"
                disabled={paymentLoading}
                onClick={() => void submitPayment()}
                className="flex-1 rounded-xl bg-[#543923] px-4 py-3 text-sm font-bold text-white transition hover:bg-[#69452d] disabled:cursor-not-allowed disabled:opacity-50"
              >
                {paymentLoading ? "Memproses..." : "Konfirmasi"}
              </button>
            </div>
          </section>
        </div>
      )}
    </main>
  );
}

function SummaryCard({
  title,
  value,
  description,
  icon,
}: {
  title: string;
  value: string;
  description: string;
  icon: string;
}) {
  return (
    <div className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm">
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium text-[#92775f]">
          {title}
        </p>

        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#f1e6da] text-sm font-bold text-[#69452d]">
          {icon}
        </span>
      </div>

      <p className="mt-4 text-2xl font-bold tracking-tight">
        {value}
      </p>

      <p className="mt-1 text-xs text-[#92775f]">
        {description}
      </p>
    </div>
  );
}

function InfoBox({
  label,
  value,
}: {
  label: string;
  value: string;
}) {
  return (
    <div className="rounded-xl border border-[#eadccf] bg-white p-3">
      <p className="text-xs text-[#92775f]">{label}</p>
      <p className="mt-1 break-words text-sm font-semibold">
        {value}
      </p>
    </div>
  );
}

function TotalRow({
  label,
  value,
  strong = false,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <div
      className={`flex items-center justify-between gap-4 ${
        strong ? "text-base font-bold" : "text-sm text-[#76604c]"
      }`}
    >
      <span>{label}</span>
      <span className={strong ? "text-[#543923]" : ""}>
        {value}
      </span>
    </div>
  );
}