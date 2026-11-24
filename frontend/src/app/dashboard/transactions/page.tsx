"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";
import { apiRequest } from "@/lib/api";

type SalesChannel = "OFFLINE" | "GRABFOOD";
type Temperature = "HOT" | "ICED";
type PaymentMethod = "CASH" | "QRIS" | "TRANSFER" | "GRAB_PLATFORM";

type Product = {
  id: number;
  name: string;
  sku?: string | null;
  temperature_options?: Temperature[] | null;
  category?: { id: number; name: string } | null;
  prices: {
    id: number;
    sales_channel: SalesChannel;
    price: number | string;
  }[];
};

type CartItem =
  | {
      key: string;
      type: "CATALOG";
      product: Product;
      quantity: number;
      temperature: Temperature | null;
    }
  | {
      key: string;
      type: "MANUAL";
      name: string;
      unit_price: number;
      quantity: number;
    };

type ReceiptItem = {
  id: number;
  product_name_snapshot: string;
  temperature: Temperature | null;
  unit_price: number;
  quantity: number;
  subtotal: number;
};

type Payment = {
  method: PaymentMethod;
  amount: number;
  cash_received?: number | null;
  change_amount?: number | null;
  reference_number?: string | null;
};

type Transaction = {
  id: number;
  invoice_number: string;
  customer_name: string | null;
  sales_channel: SalesChannel;
  payment_status: string;
  subtotal: number;
  discount: number;
  total: number;
  notes: string | null;
  created_at: string;
  items: ReceiptItem[];
  payment?: Payment | null;
};

const rupiah = (value: number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value || 0));

type ApiErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

const getError = (result: unknown, fallback: string): string => {
  if (
    typeof result !== "object" ||
    result === null
  ) {
    return fallback;
  }

  const payload = result as ApiErrorResponse;

  if (payload.errors) {
    return Object.values(payload.errors)
      .flat()
      .join(" ");
  }

  return payload.message || fallback;
};

const escapeHtml = (value: unknown) =>
  String(value ?? "").replace(/[&<>"']/g, (character) => {
    const entities: Record<string, string> = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return entities[character];
  });

export default function TransactionsPage() {
  const [channel, setChannel] = useState<SalesChannel>("OFFLINE");
  const [products, setProducts] = useState<Product[]>([]);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [categories, setCategories] = useState<string[]>(["Semua"]);
  const [selectedCategory, setSelectedCategory] = useState("Semua");
  const [search, setSearch] = useState("");

  const [customerName, setCustomerName] = useState("");
  const [notes, setNotes] = useState("");

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [paying, setPaying] = useState(false);

  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const [pendingProduct, setPendingProduct] = useState<Product | null>(null);

  const [manualName, setManualName] = useState("");
  const [manualPrice, setManualPrice] = useState("");
  const [manualQuantity, setManualQuantity] = useState("1");

  const [pendingTransaction, setPendingTransaction] =
    useState<Transaction | null>(null);

  const [paymentMethod, setPaymentMethod] =
    useState<PaymentMethod>("CASH");

  const [cashReceived, setCashReceived] = useState("");
  const [paymentReference, setPaymentReference] = useState("");

  const [receipt, setReceipt] = useState<Transaction | null>(null);
  const [showReceipt, setShowReceipt] = useState(false);

  const loadProducts = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const response = await apiRequest(
        `/products?sales_channel=${channel}`
      );

      if (response.status === 401) {
        window.location.replace("/");
        return;
      }

      if (response.status === 403) {
        throw new Error("Kamu tidak memiliki akses katalog produk.");
      }

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || "Gagal memuat katalog.");
      }

      const list: Product[] = result.data?.products || [];

      setProducts(list);

      setCategories([
        "Semua",
        ...Array.from(
          new Set(
            list
              .map((product) => product.category?.name)
              .filter((name): name is string => Boolean(name))
          )
        ),
      ]);
    } catch (err) {
      setProducts([]);
      setError(
        err instanceof Error ? err.message : "Koneksi bermasalah."
      );
    } finally {
      setLoading(false);
    }
  }, [channel]);

  useEffect(() => {
    queueMicrotask(() => {
      void loadProducts();
    });
  }, [loadProducts]);

  const getPrice = (product: Product) => {
    const price = product.prices?.find(
      (item) => item.sales_channel === channel
    );

    return Number(price?.price || 0);
  };

  const filteredProducts = useMemo(
    () =>
      products.filter((product) => {
        const matchSearch = product.name
          .toLowerCase()
          .includes(search.toLowerCase());

        const matchCategory =
          selectedCategory === "Semua" ||
          product.category?.name === selectedCategory;

        return matchSearch && matchCategory;
      }),
    [products, search, selectedCategory]
  );

  const addToCart = (
    product: Product,
    temperature: Temperature | null
  ) => {
    if (getPrice(product) <= 0) {
      setError("Produk belum memiliki harga aktif.");
      return;
    }

    const key = `catalog-${product.id}-${temperature || "NONE"}`;

    setCart((current) => {
      const existing = current.find((item) => item.key === key);

      if (existing) {
        return current.map((item) =>
          item.key === key
            ? { ...item, quantity: item.quantity + 1 }
            : item
        );
      }

      return [
        ...current,
        {
          key,
          type: "CATALOG",
          product,
          quantity: 1,
          temperature,
        },
      ];
    });

    setPendingProduct(null);
    setError("");
  };

  const handleProductClick = (product: Product) => {
    const options = product.temperature_options || [];

    if (options.length > 1) {
      setPendingProduct(product);
      return;
    }

    addToCart(product, options.length === 1 ? options[0] : null);
  };

  const addManualItem = () => {
    const name = manualName.trim();
    const price = Number(manualPrice);
    const quantity = Number(manualQuantity);

    if (!name) {
      setError("Nama item manual wajib diisi.");
      return;
    }

    if (!Number.isSafeInteger(price) || price < 1) {
      setError("Harga harus berupa angka rupiah minimal Rp1.");
      return;
    }

    if (!Number.isSafeInteger(quantity) || quantity < 1) {
      setError("Jumlah harus minimal 1.");
      return;
    }

    setCart((current) => [
      ...current,
      {
        key: `manual-${Date.now()}-${Math.random()
          .toString(36)
          .slice(2, 8)}`,
        type: "MANUAL",
        name,
        unit_price: price,
        quantity,
      },
    ]);

    setManualName("");
    setManualPrice("");
    setManualQuantity("1");
    setError("");
  };

  const updateQuantity = (key: string, amount: number) => {
    setCart((current) =>
      current
        .map((item) =>
          item.key === key
            ? { ...item, quantity: item.quantity + amount }
            : item
        )
        .filter((item) => item.quantity > 0)
    );
  };

  const totalItems = cart.reduce(
    (total, item) => total + item.quantity,
    0
  );

  const grandTotal = cart.reduce((total, item) => {
    const price =
      item.type === "CATALOG"
        ? getPrice(item.product)
        : item.unit_price;

    return total + price * item.quantity;
  }, 0);

  // Membuat transaksi satu kali. Pembayaran dilakukan pada tahap berikutnya.
  const saveTransaction = async () => {
    if (!cart.length) {
      setError("Keranjang masih kosong.");
      return;
    }

    setSaving(true);
    setError("");
    setMessage("");

    try {
      const response = await apiRequest("/transactions", {
        method: "POST",
        body: JSON.stringify({
          customer_name: customerName.trim() || null,
          sales_channel: channel,
          notes: notes.trim() || null,
          items: cart.map((item) =>
            item.type === "CATALOG"
              ? {
                  item_type: "CATALOG",
                  product_id: item.product.id,
                  quantity: item.quantity,
                  temperature: item.temperature,
                }
              : {
                  item_type: "MANUAL",
                  name: item.name,
                  unit_price: item.unit_price,
                  quantity: item.quantity,
                }
          ),
        }),
      });

      const result = await response.json();

      if (response.status === 401) {
        window.location.replace("/");
        return;
      }

      if (response.status === 403) {
        throw new Error("Tidak memiliki izin membuat transaksi.");
      }

      if (!response.ok) {
        throw new Error(getError(result, "Gagal menyimpan pesanan."));
      }

      const transaction: Transaction =
        result.data?.transaction || result.data;

      if (!transaction?.id || Number(transaction.total) <= 0) {
        throw new Error("Data transaksi dari server tidak lengkap.");
      }

      setPendingTransaction(transaction);
      setPaymentMethod(
        transaction.sales_channel === "GRABFOOD"
          ? "GRAB_PLATFORM"
          : "CASH"
      );
      setCashReceived(String(transaction.total));
      setPaymentReference("");

      setMessage(
        `Invoice ${transaction.invoice_number} berhasil dibuat. Silakan lanjutkan pembayaran.`
      );
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Gagal membuat transaksi."
      );
    } finally {
      setSaving(false);
    }
  };

  const cashValue = Number(cashReceived || 0);
  const paymentTotal = Number(pendingTransaction?.total || 0);

  const cashValid =
    Number.isSafeInteger(cashValue) && cashValue >= paymentTotal;

  const changeAmount = cashValid ? cashValue - paymentTotal : 0;

  // Memproses pembayaran pada invoice yang sama.
  const confirmPayment = async () => {
    if (!pendingTransaction) return;

    if (paymentMethod === "CASH" && !cashValid) {
      setError("Uang diterima kurang dari total pembayaran.");
      return;
    }

    if (
      pendingTransaction.sales_channel === "GRABFOOD" &&
      paymentMethod !== "GRAB_PLATFORM"
    ) {
      setError("Pesanan GrabFood harus menggunakan Grab Platform.");
      return;
    }

    if (
      pendingTransaction.sales_channel === "OFFLINE" &&
      paymentMethod === "GRAB_PLATFORM"
    ) {
      setError("Grab Platform hanya untuk pesanan GrabFood.");
      return;
    }

    setPaying(true);
    setError("");

    try {
      const response = await apiRequest(
        `/transactions/${pendingTransaction.id}/payment`,
        {
          method: "POST",
          body: JSON.stringify({
            method: paymentMethod,
            cash_received:
              paymentMethod === "CASH" ? cashValue : null,
            reference_number:
              paymentReference.trim() || null,
          }),
        }
      );

      const result = await response.json();

      if (response.status === 401) {
        window.location.replace("/");
        return;
      }

      if (response.status === 403) {
        throw new Error("Tidak memiliki izin memproses pembayaran.");
      }

      if (!response.ok) {
        throw new Error(getError(result, "Pembayaran gagal."));
      }

      const paidTransaction: Transaction =
        result.data?.transaction || result.data;

      if (
        paidTransaction?.payment_status !== "PAID" ||
        !paidTransaction?.payment
      ) {
        throw new Error(
          "Server belum mengonfirmasi pembayaran PAID. Periksa transaksi sebelum mencoba lagi."
        );
      }

      setReceipt(paidTransaction);
      setShowReceipt(true);
      setPendingTransaction(null);

      setCart([]);
      setCustomerName("");
      setNotes("");
      setManualName("");
      setManualPrice("");
      setManualQuantity("1");

      setMessage(
        `Pembayaran invoice ${paidTransaction.invoice_number} berhasil.`
      );
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Pembayaran gagal."
      );
    } finally {
      setPaying(false);
    }
  };

  const printReceipt = (destination: "thermal" | "pdf") => {
    if (!receipt || receipt.payment_status !== "PAID") return;

    const printWindow = window.open(
      "",
      "_blank",
      "width=420,height=720"
    );

    if (!printWindow) {
      setError("Pop-up diblokir. Izinkan pop-up untuk LABOR_STATION.");
      return;
    }

    const itemRows = receipt.items
      .map(
        (item) => `
          <div class="item">
            <strong>${escapeHtml(item.product_name_snapshot)}</strong>
            ${
              item.temperature
                ? `<div>${item.temperature === "HOT" ? "Panas (HOT)" : "Dingin (ICED)"}</div>`
                : ""
            }
            <div class="line">
              <span>${item.quantity} × ${rupiah(item.unit_price)}</span>
              <strong>${rupiah(item.subtotal)}</strong>
            </div>
          </div>
        `
      )
      .join("");

    const payment = receipt.payment;

    const paymentDetails = payment
      ? `
        <div class="line"><span>Metode</span><strong>${escapeHtml(payment.method)}</strong></div>
        <div class="line"><span>Dibayar</span><span>${rupiah(payment.amount)}</span></div>
        ${
          payment.method === "CASH"
            ? `
              <div class="line"><span>Uang diterima</span><span>${rupiah(Number(payment.cash_received || 0))}</span></div>
              <div class="line"><strong>Kembalian</strong><strong>${rupiah(Number(payment.change_amount || 0))}</strong></div>
            `
            : ""
        }
        ${
          payment.reference_number
            ? `<div class="line"><span>Referensi</span><span>${escapeHtml(payment.reference_number)}</span></div>`
            : ""
        }
      `
      : "";

    const date = new Date(receipt.created_at).toLocaleString("id-ID");

    printWindow.document.open();

    printWindow.document.write(`
      <!DOCTYPE html>
      <html lang="id">
      <head>
        <meta charset="UTF-8" />
        <title>Struk LABOR_STATION</title>
        <style>
          @page { size: 80mm auto; margin: 0; }
          * { box-sizing: border-box; }
          body {
            width: 80mm;
            margin: 0;
            padding: 4mm;
            color: #171717;
            font-family: Arial, sans-serif;
            font-size: 10pt;
          }
          .center { text-align: center; }
          .brand { font-size: 17pt; font-weight: 800; }
          .subtitle { font-size: 7pt; letter-spacing: 1px; }
          .divider { border-top: 1px dashed #777; margin: 4mm 0; }
          .line { display: flex; justify-content: space-between; gap: 3mm; margin: 2mm 0; }
          .item { margin-bottom: 4mm; }
          .item > div { margin-top: 1mm; font-size: 8pt; }
          .total { font-size: 12pt; font-weight: 800; }
          .thanks { margin-top: 6mm; font-size: 8pt; }
        </style>
      </head>
      <body>
        <header class="center">
          <div class="brand">LABOR_STATION</div>
          <p class="subtitle">COFFEE STATION MANAGEMENT</p>
          <strong>STRUK PEMBAYARAN</strong>
        </header>

        <div class="divider"></div>

        <div class="line">
          <span>Invoice</span>
          <strong>${escapeHtml(receipt.invoice_number)}</strong>
        </div>
        <div class="line"><span>Tanggal</span><span>${escapeHtml(date)}</span></div>
        <div class="line"><span>Channel</span><span>${escapeHtml(receipt.sales_channel)}</span></div>
        ${
          receipt.customer_name
            ? `<div class="line"><span>Pelanggan</span><span>${escapeHtml(receipt.customer_name)}</span></div>`
            : ""
        }

        <div class="divider"></div>
        <strong>DETAIL PESANAN</strong>
        <div style="margin-top: 4mm">${itemRows}</div>

        <div class="divider"></div>
        <div class="line"><span>Subtotal</span><span>${rupiah(receipt.subtotal)}</span></div>
        <div class="line"><span>Diskon</span><span>${rupiah(receipt.discount)}</span></div>
        <div class="line total"><span>TOTAL</span><span>${rupiah(receipt.total)}</span></div>

        <div class="divider"></div>
        ${paymentDetails}

        <div class="divider"></div>
        <p class="center"><strong>STATUS: PAID</strong></p>
        <footer class="center thanks">
          <p>Terima kasih telah memesan.</p>
          <p>Sampai jumpa kembali!</p>
        </footer>
      </body>
      </html>
    `);

    printWindow.document.close();

    if (destination === "thermal") {
      printWindow.onload = () => {
        printWindow.focus();
        window.setTimeout(() => printWindow.print(), 300);
      };

      printWindow.onafterprint = () => printWindow.close();
      return;
    }

    const element = printWindow.document.body;

    html2canvas(element, {
      scale: 3,
      backgroundColor: "#ffffff",
      useCORS: true,
      logging: false,
      windowWidth: 400,
    })
      .then((canvas) => {
        const width = 80;
        const height = (canvas.height * width) / canvas.width;

        const pdf = new jsPDF({
          orientation: "portrait",
          unit: "mm",
          format: [width, height],
          compress: true,
        });

        pdf.addImage(
          canvas.toDataURL("image/png"),
          "PNG",
          0,
          0,
          width,
          height,
          undefined,
          "FAST"
        );

        pdf.save(`Struk-${receipt.invoice_number}.pdf`);
        printWindow.close();
      })
      .catch((err) => {
        console.error(err);
        setError("Gagal membuat PDF.");
        printWindow.close();
      });
  };

  const changeChannel = (next: SalesChannel) => {
    if (next === channel) return;

    if (cart.length > 0) {
      const confirmed = window.confirm(
        "Mengganti kanal akan mengosongkan keranjang karena harga dapat berbeda. Lanjutkan?"
      );

      if (!confirmed) return;
    }

    setCart([]);
    setChannel(next);
    setSelectedCategory("Semua");
    setError("");
    setMessage("");
  };

  const isCash = paymentMethod === "CASH";

  return (
    <div className="min-h-screen bg-[#f5eee5] text-[#382315]">
      <header className="sticky top-0 z-30 border-b border-[#ead9c8] bg-[#fffaf5] px-5 py-4 md:px-8">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <a
              href="/dashboard"
              className="inline-flex rounded-xl border border-[#dfc7b0] bg-white px-4 py-2.5 text-sm font-semibold"
            >
              ← Kembali ke Dashboard
            </a>
            <p className="mt-3 text-xs text-[#a47b5d]">
              Workspace / Transaksi
            </p>
            <h1 className="text-xl font-bold">Transaksi Kasir</h1>
          </div>

          <div className="sm:text-right">
            <p className="font-semibold">LABOR_STATION</p>
            <p className="text-xs text-[#a47b5d]">
              Coffee Station Management
            </p>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-[1500px] p-4 md:p-8">
        <div className="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-center">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#a47b5d]">
              Point of Sale
            </p>
            <h2 className="mt-1 text-2xl font-bold md:text-3xl">
              Buat Pesanan Baru
            </h2>
            <p className="mt-2 text-sm text-[#92745d]">
              Kelola pesanan pelanggan dengan mudah.
            </p>
          </div>

          <button
            onClick={loadProducts}
            disabled={loading || Boolean(pendingTransaction)}
            className="rounded-xl border border-[#dfc7b0] bg-white px-5 py-3 text-sm font-semibold disabled:opacity-50"
          >
            ↻ Refresh Katalog
          </button>
        </div>

        <section className="mb-6 rounded-2xl border border-[#ead9c8] bg-white p-5 shadow-sm">
          <h3 className="mb-4 font-bold">Pilih Kanal Penjualan</h3>
          <div className="grid grid-cols-2 gap-3">
            {(["OFFLINE", "GRABFOOD"] as SalesChannel[]).map((item) => (
              <button
                key={item}
                onClick={() => changeChannel(item)}
                disabled={Boolean(pendingTransaction)}
                className={`rounded-xl border p-4 text-left disabled:opacity-50 ${
                  channel === item
                    ? "border-[#694329] bg-[#4b2d1c] text-white"
                    : "border-[#ead9c8] bg-[#fffaf5]"
                }`}
              >
                <span className="text-2xl">
                  {item === "OFFLINE" ? "☕" : "🛵"}
                </span>
                <p className="mt-2 font-bold">{item}</p>
                <p className="mt-1 text-xs opacity-75">
                  {item === "OFFLINE"
                    ? "Penjualan langsung di kedai"
                    : "Pesanan melalui GrabFood"}
                </p>
              </button>
            ))}
          </div>
        </section>

        {error && (
          <div className="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {error}
          </div>
        )}

        {message && (
          <div className="mb-5 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {message}
          </div>
        )}

        <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[1fr_390px]">
          <section className="min-w-0">

            {/* TAMBAH ITEM MANUAL - DIPINDAHKAN KE ATAS */}
            <section className="mb-6 rounded-2xl border border-[#ead9c8] bg-white p-5">
              <h3 className="font-bold">Tambah Item Manual</h3>
              <p className="mt-1 text-xs text-[#98785e]">
                Untuk makanan atau item di luar katalog produk.
              </p>

              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1.5fr_1fr_100px_auto]">
                <input
                  value={manualName}
                  onChange={(event) => setManualName(event.target.value)}
                  disabled={Boolean(pendingTransaction)}
                  placeholder="Nama item"
                  className="rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm"
                />
                <input
                  type="number"
                  min="1"
                  step="1"
                  value={manualPrice}
                  onChange={(event) => setManualPrice(event.target.value)}
                  disabled={Boolean(pendingTransaction)}
                  placeholder="Harga satuan"
                  className="rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm"
                />
                <input
                  type="number"
                  min="1"
                  step="1"
                  value={manualQuantity}
                  onChange={(event) => setManualQuantity(event.target.value)}
                  disabled={Boolean(pendingTransaction)}
                  className="rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm"
                />
                <button
                  onClick={addManualItem}
                  disabled={Boolean(pendingTransaction)}
                  className="rounded-xl bg-[#4b2d1c] px-4 py-3 text-sm font-bold text-white disabled:opacity-50"
                >
                  Tambah
                </button>
              </div>
            </section>

            {/* PENCARIAN DAN FILTER KATALOG */}
            <div className="mb-4 flex flex-col gap-3 sm:flex-row">
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Cari nama produk..."
                className="min-w-0 flex-1 rounded-xl border border-[#e4d1bd] bg-white px-4 py-3 text-sm"
              />

              <select
                value={selectedCategory}
                onChange={(event) => setSelectedCategory(event.target.value)}
                className="rounded-xl border border-[#e4d1bd] bg-white px-4 py-3 text-sm"
              >
                {categories.map((category) => (
                  <option key={category}>{category}</option>
                ))}
              </select>
            </div>

            {/* KATALOG PRODUK */}
            <div className="mb-4 flex justify-between">
              <h3 className="text-lg font-bold">Katalog Produk</h3>
              <span className="text-xs text-[#9b795e]">
                {products.length} produk tersedia
              </span>
            </div>

            {loading ? (
              <div className="rounded-2xl bg-white p-10 text-center">
                Memuat katalog produk...
              </div>
            ) : filteredProducts.length === 0 ? (
              <div className="rounded-2xl border border-[#ead9c8] bg-white p-10 text-center">
                <p className="text-3xl">☕</p>
                <p className="mt-3 font-bold">
                  Belum ada produk tersedia
                </p>
                <p className="mt-1 text-sm text-[#98785e]">
                  Produk tidak ditemukan atau belum memiliki harga aktif.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {filteredProducts.map((product) => (
                  <button
                    key={product.id}
                    onClick={() => handleProductClick(product)}
                    disabled={Boolean(pendingTransaction)}
                    className="rounded-2xl border border-[#ead9c8] bg-white p-4 text-left shadow-sm transition hover:-translate-y-1 hover:shadow-md disabled:opacity-50"
                  >
                    <div className="flex h-24 items-center justify-center rounded-xl bg-[#f5eee5] text-4xl">
                      ☕
                    </div>
                    <p className="mt-3 min-h-10 text-sm font-bold">
                      {product.name}
                    </p>
                    <p className="mt-1 text-xs text-[#a47b5d]">
                      {product.category?.name || "Tanpa kategori"}
                    </p>
                    {product.temperature_options?.length ? (
                      <p className="mt-2 text-[10px] font-semibold text-[#8c6040]">
                        {product.temperature_options.join(" / ")}
                      </p>
                    ) : null}
                    <div className="mt-3 flex justify-between">
                      <span className="text-sm font-bold text-[#694329]">
                        {rupiah(getPrice(product))}
                      </span>
                      <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-[#4b2d1c] text-white">
                        +
                      </span>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </section>

          {/* KERANJANG PESANAN */}
          <aside className="flex flex-col rounded-2xl border border-[#ead9c8] bg-white p-4 shadow-sm xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)]">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-bold">Keranjang Pesanan</h3>
              <span className="rounded-full bg-[#f3e5d6] px-3 py-1 text-xs font-bold">
                {totalItems} item
              </span>
            </div>

            <div className="mt-4">
              <label className="mb-2 block text-xs font-semibold text-[#8c6b51]">
                Nama Pelanggan (Opsional)
              </label>
              <input
                value={customerName}
                onChange={(event) => setCustomerName(event.target.value)}
                disabled={Boolean(pendingTransaction)}
                placeholder="Nama pelanggan"
                className="w-full rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm"
              />
            </div>

            <div className="mt-4">
              <label className="mb-2 block text-xs font-semibold text-[#8c6b51]">
                Catatan Pesanan
              </label>
              <textarea
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                disabled={Boolean(pendingTransaction)}
                placeholder="Catatan tambahan"
                rows={2}
                className="w-full resize-none rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm"
              />
            </div>

            <div className="my-5 border-t border-dashed border-[#e4d1bd]" />

            <div className="min-h-0 space-y-4 overflow-y-auto xl:flex-1">
              {cart.length === 0 ? (
                <div className="py-8 text-center">
                  <p className="text-4xl">🛒</p>
                  <p className="mt-3 text-sm font-semibold">
                    Keranjang masih kosong
                  </p>
                </div>
              ) : (
                cart.map((item) => {
                  const catalog = item.type === "CATALOG";
                  const name = catalog ? item.product.name : item.name;
                  const price = catalog
                    ? getPrice(item.product)
                    : item.unit_price;

                  return (
                    <div
                      key={item.key}
                      className="border-b border-[#f0e6dc] pb-4"
                    >
                      <div className="flex justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-sm font-bold">{name}</p>
                          {catalog && item.temperature && (
                            <p className="mt-1 text-xs text-[#8c6040]">
                              {item.temperature === "HOT"
                                ? "Panas (HOT)"
                                : "Dingin (ICED)"}
                            </p>
                          )}
                          {!catalog && (
                            <p className="mt-1 text-[10px] font-semibold text-[#a47b5d]">
                              ITEM MANUAL
                            </p>
                          )}
                          <p className="mt-1 text-xs text-[#98785e]">
                            {rupiah(price)}
                          </p>
                        </div>

                        <button
                          onClick={() =>
                            setCart((current) =>
                              current.filter(
                                (entry) => entry.key !== item.key
                              )
                            )
                          }
                          disabled={Boolean(pendingTransaction)}
                          className="text-xs text-red-500 disabled:opacity-50"
                        >
                          Hapus
                        </button>
                      </div>

                      <div className="mt-3 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <button
                            onClick={() => updateQuantity(item.key, -1)}
                            disabled={Boolean(pendingTransaction)}
                            className="h-8 w-8 rounded-lg border border-[#e4d1bd] font-bold disabled:opacity-50"
                          >
                            −
                          </button>
                          <span className="min-w-5 text-center text-sm font-bold">
                            {item.quantity}
                          </span>
                          <button
                            onClick={() => updateQuantity(item.key, 1)}
                            disabled={Boolean(pendingTransaction)}
                            className="h-8 w-8 rounded-lg border border-[#e4d1bd] font-bold disabled:opacity-50"
                          >
                            +
                          </button>
                        </div>
                        <p className="text-sm font-bold">
                          {rupiah(price * item.quantity)}
                        </p>
                      </div>
                    </div>
                  );
                })
              )}
            </div>

            <div className="mt-4 shrink-0 border-t border-[#ead9c8] pt-4">
              <div className="flex justify-between text-sm text-[#98785e]">
                <span>Total item</span>
                <span>{totalItems}</span>
              </div>
              <div className="mt-3 flex items-center justify-between">
                <span className="font-bold">Total Pembayaran</span>
                <span className="text-xl font-bold text-[#694329]">
                  {rupiah(grandTotal)}
                </span>
              </div>

              <button
                onClick={saveTransaction}
                disabled={
                  saving ||
                  loading ||
                  cart.length === 0 ||
                  Boolean(pendingTransaction)
                }
                className="mt-5 w-full rounded-xl bg-[#4b2d1c] px-4 py-4 text-sm font-bold text-white disabled:opacity-40"
              >
                {saving ? "Menyimpan Pesanan..." : "Simpan Pesanan"}
              </button>
              <p className="mt-3 text-center text-xs text-[#a47b5d]">
                Lanjutkan pembayaran setelah pesanan disimpan.
              </p>
            </div>
          </aside>
        </div>
      </main>

      {/* MODAL PEMBAYARAN */}
      {pendingTransaction && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-3">
          <div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl sm:p-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 className="text-xl font-extrabold">
                  Pembayaran Pesanan
                </h2>
                <p className="mt-1 text-xs text-[#98785e]">
                  Invoice {pendingTransaction.invoice_number}
                </p>
              </div>
              <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">
                BELUM LUNAS
              </span>
            </div>

            <div className="mt-5 rounded-xl bg-[#fff8f0] p-4">
              <p className="text-xs text-[#98785e]">
                Total yang harus dibayar
              </p>
              <p className="mt-1 text-2xl font-extrabold text-[#4b2d1c]">
                {rupiah(paymentTotal)}
              </p>
            </div>

            <div className="mt-5">
              <label className="mb-2 block text-sm font-bold">
                Metode Pembayaran
              </label>

              {pendingTransaction.sales_channel === "GRABFOOD" ? (
                <div className="rounded-xl border border-[#dfc7b0] bg-[#fffaf5] p-4">
                  <p className="font-bold">GRAB PLATFORM</p>
                  <p className="mt-1 text-xs text-[#98785e]">
                    Konfirmasi setelah pembayaran tercatat di platform GrabFood.
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-3 gap-2">
                  {(["CASH", "QRIS", "TRANSFER"] as PaymentMethod[]).map(
                    (method) => (
                      <button
                        key={method}
                        onClick={() => setPaymentMethod(method)}
                        className={`rounded-xl border p-3 text-xs font-bold ${
                          paymentMethod === method
                            ? "border-[#694329] bg-[#4b2d1c] text-white"
                            : "border-[#ead9c8] bg-[#fffaf5]"
                        }`}
                      >
                        {method}
                      </button>
                    )
                  )}
                </div>
              )}
            </div>

            {isCash && (
              <div className="mt-4">
                <label className="mb-2 block text-sm font-bold">
                  Uang Diterima (Rp)
                </label>
                <input
                  type="number"
                  min={paymentTotal}
                  step="1"
                  value={cashReceived}
                  onChange={(event) => setCashReceived(event.target.value)}
                  className="w-full rounded-xl border border-[#e4d1bd] px-4 py-3 text-lg"
                  placeholder="Masukkan nominal uang"
                />

                <div className="mt-3 flex justify-between rounded-xl bg-[#f5eee5] p-3 text-sm">
                  <span>Kembalian</span>
                  <strong
                    className={cashValid ? "text-green-700" : "text-red-600"}
                  >
                    {rupiah(changeAmount)}
                  </strong>
                </div>

                {!cashValid && (
                  <p className="mt-2 text-xs text-red-600">
                    Uang diterima masih kurang.
                  </p>
                )}
              </div>
            )}

            {(paymentMethod === "QRIS" ||
              paymentMethod === "TRANSFER") && (
              <div className="mt-4">
                <label className="mb-2 block text-sm font-bold">
                  Nomor Referensi (Opsional)
                </label>
                <input
                  value={paymentReference}
                  onChange={(event) =>
                    setPaymentReference(event.target.value)
                  }
                  maxLength={100}
                  className="w-full rounded-xl border border-[#e4d1bd] px-4 py-3 text-sm"
                  placeholder="Nomor referensi"
                />
                <p className="mt-2 text-xs text-[#98785e]">
                  QRIS dan transfer dikonfirmasi secara manual oleh kasir.
                </p>
              </div>
            )}

            {paymentMethod === "GRAB_PLATFORM" && (
              <p className="mt-4 text-xs text-[#98785e]">
                Konfirmasi ini mencatat pembayaran yang sudah tercatat di GrabFood,
                bukan integrasi otomatis dengan Grab.
              </p>
            )}

            <div className="mt-6">
              <button
                onClick={confirmPayment}
                disabled={paying || (isCash && !cashValid)}
                className="w-full rounded-xl bg-[#4b2d1c] px-4 py-3 font-bold text-white disabled:opacity-40"
              >
                {paying ? "Memproses Pembayaran..." : "Konfirmasi Pembayaran"}
              </button>
              <p className="mt-3 text-center text-[11px] text-[#98785e]">
                Struk hanya tersedia setelah server mengonfirmasi status PAID.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* STRUK FINAL */}
      {showReceipt && receipt && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center overflow-hidden bg-black/50 p-2 sm:p-4">
          <div className="flex max-h-[calc(100dvh-16px)] w-full max-w-sm flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-[#ead9c8] px-4 py-3">
              <div>
                <h2 className="text-lg font-bold">Transaksi Lunas</h2>
                <p className="text-xs text-[#98785e]">
                  Struk pembayaran LABOR_STATION
                </p>
              </div>
              <button
                onClick={() => setShowReceipt(false)}
                className="rounded-lg px-3 py-2 text-2xl"
              >
                ×
              </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
              <div className="mx-auto w-full max-w-[80mm] border border-dashed border-[#d9c5b2] bg-white p-3 text-[#382315]">
                <div className="text-center">
                  <h2 className="text-xl font-extrabold tracking-wide">
                    LABOR_STATION
                  </h2>
                  <p className="mt-1 text-[10px] tracking-widest text-[#98785e]">
                    COFFEE STATION MANAGEMENT
                  </p>
                  <p className="mt-4 text-xs font-bold">
                    STRUK PEMBAYARAN
                  </p>
                </div>

                <div className="my-4 border-t border-dashed border-[#cdb9a5]" />

                <div className="space-y-2 text-xs">
                  <div className="flex justify-between gap-3">
                    <span>Invoice</span>
                    <span className="font-semibold">
                      {receipt.invoice_number}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span>Tanggal</span>
                    <span>
                      {new Date(receipt.created_at).toLocaleString("id-ID")}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span>Channel</span>
                    <span>{receipt.sales_channel}</span>
                  </div>
                  {receipt.customer_name && (
                    <div className="flex justify-between gap-3">
                      <span>Pelanggan</span>
                      <span>{receipt.customer_name}</span>
                    </div>
                  )}
                </div>

                <div className="my-4 border-t border-dashed border-[#cdb9a5]" />

                <p className="mb-3 text-xs font-bold">DETAIL PESANAN</p>

                <div className="space-y-3">
                  {receipt.items.map((item) => (
                    <div key={item.id} className="text-xs">
                      <p className="font-bold">
                        {item.product_name_snapshot}
                      </p>
                      {item.temperature && (
                        <p className="mt-1 text-[10px]">
                          {item.temperature === "HOT"
                            ? "Panas (HOT)"
                            : "Dingin (ICED)"}
                        </p>
                      )}
                      <div className="mt-1 flex justify-between gap-2">
                        <span>
                          {item.quantity} × {rupiah(item.unit_price)}
                        </span>
                        <span className="font-semibold">
                          {rupiah(item.subtotal)}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="my-4 border-t border-dashed border-[#cdb9a5]" />

                <div className="space-y-2 text-xs">
                  <div className="flex justify-between">
                    <span>Subtotal</span>
                    <span>{rupiah(receipt.subtotal)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span>Diskon</span>
                    <span>{rupiah(receipt.discount)}</span>
                  </div>
                  <div className="flex justify-between pt-2 text-sm font-extrabold">
                    <span>TOTAL</span>
                    <span>{rupiah(receipt.total)}</span>
                  </div>
                </div>

                {receipt.payment && (
                  <>
                    <div className="my-4 border-t border-dashed border-[#cdb9a5]" />
                    <div className="space-y-2 text-xs">
                      <div className="flex justify-between">
                        <span>Metode</span>
                        <strong>{receipt.payment.method}</strong>
                      </div>
                      <div className="flex justify-between">
                        <span>Dibayar</span>
                        <span>{rupiah(receipt.payment.amount)}</span>
                      </div>
                      {receipt.payment.method === "CASH" && (
                        <>
                          <div className="flex justify-between">
                            <span>Uang diterima</span>
                            <span>
                              {rupiah(
                                Number(receipt.payment.cash_received || 0)
                              )}
                            </span>
                          </div>
                          <div className="flex justify-between font-bold">
                            <span>Kembalian</span>
                            <span>
                              {rupiah(
                                Number(receipt.payment.change_amount || 0)
                              )}
                            </span>
                          </div>
                        </>
                      )}
                      {receipt.payment.reference_number && (
                        <div className="flex justify-between gap-2">
                          <span>Referensi</span>
                          <span>{receipt.payment.reference_number}</span>
                        </div>
                      )}
                    </div>
                  </>
                )}

                <div className="my-4 border-t border-dashed border-[#cdb9a5]" />

                <div className="text-center text-xs">
                  <p className="font-bold">
                    STATUS: {receipt.payment_status}
                  </p>
                  <p className="mt-4 text-[10px] text-[#80664e]">
                    Terima kasih telah memesan.
                  </p>
                  <p className="mt-1 text-[10px] text-[#80664e]">
                    Sampai jumpa kembali!
                  </p>
                </div>
              </div>

              <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <button
                  onClick={() => printReceipt("thermal")}
                  className="rounded-xl bg-[#4b2d1c] px-4 py-3 text-sm font-bold text-white"
                >
                  Cetak Thermal 80 mm
                </button>
                <button
                  onClick={() => printReceipt("pdf")}
                  className="rounded-xl border border-[#dfc7b0] bg-[#fffaf5] px-4 py-3 text-sm font-bold text-[#694329]"
                >
                  Simpan PDF 80 mm
                </button>
                <button
                  onClick={() => setShowReceipt(false)}
                  className="rounded-xl border border-[#dfc7b0] px-4 py-3 text-sm font-semibold text-[#694329] sm:col-span-2"
                >
                  Tutup
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* PILIH SUHU MINUMAN */}
      {pendingProduct && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-sm rounded-2xl border border-[#ead9c8] bg-[#fffaf5] p-6 shadow-2xl">
            <div className="text-center">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[#f0e1d1] text-3xl">
                ☕
              </div>
              <h3 className="mt-4 text-lg font-extrabold">
                Pilih Suhu Minuman
              </h3>
              <p className="mt-1 text-sm text-[#92745d]">
                {pendingProduct.name}
              </p>
              <p className="mt-2 text-base font-bold text-[#694329]">
                {rupiah(getPrice(pendingProduct))}
              </p>
            </div>

            <div className="mt-6 grid grid-cols-2 gap-3">
              {pendingProduct.temperature_options?.includes("HOT") && (
                <button
                  onClick={() => addToCart(pendingProduct, "HOT")}
                  className="rounded-xl border border-[#dfc7b0] bg-white p-4 font-bold hover:bg-[#f3e5d6]"
                >
                  <span className="block text-2xl">♨️</span>
                  <span className="mt-2 block">HOT</span>
                  <span className="mt-1 block text-xs font-normal">
                    Panas
                  </span>
                </button>
              )}

              {pendingProduct.temperature_options?.includes("ICED") && (
                <button
                  onClick={() => addToCart(pendingProduct, "ICED")}
                  className="rounded-xl border border-[#dfc7b0] bg-white p-4 font-bold hover:bg-[#f3e5d6]"
                >
                  <span className="block text-2xl">🧊</span>
                  <span className="mt-2 block">ICED</span>
                  <span className="mt-1 block text-xs font-normal">
                    Dingin
                  </span>
                </button>
              )}
            </div>

            <button
              onClick={() => setPendingProduct(null)}
              className="mt-4 w-full rounded-xl border border-[#e4d1bd] px-4 py-3 text-sm font-semibold"
            >
              Batal
            </button>
          </div>
        </div>
      )}
    </div>
  );
}