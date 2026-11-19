"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";

const API_URL = (
  process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api"
).replace(/\/$/, "");

type SalesChannel = "OFFLINE" | "GRABFOOD";
type Temperature = "HOT" | "ICED";

type Product = {
  id: number;
  name: string;
  sku?: string | null;
  temperature_options?: Temperature[] | null;
  category?: {
    id: number;
    name: string;
  } | null;
  prices: {
    id: number;
    sales_channel: SalesChannel;
    price: number | string;
  }[];
};

type CartItem = {
  product: Product;
  quantity: number;
  temperature: Temperature | null;
};

type ReceiptItem = {
  id: number;
  product_name_snapshot: string;
  temperature: Temperature | null;
  unit_price: number;
  quantity: number;
  subtotal: number;
};

type ReceiptTransaction = {
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
};

const rupiah = (value: number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(value);

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
  const [categories, setCategories] = useState<string[]>([]);
  const [selectedCategory, setSelectedCategory] = useState("Semua");
  const [search, setSearch] = useState("");
  const [customerName, setCustomerName] = useState("");
  const [notes, setNotes] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [pendingProduct, setPendingProduct] = useState<Product | null>(null);
  const [receipt, setReceipt] = useState<ReceiptTransaction | null>(null);
  const [showReceipt, setShowReceipt] = useState(false);

  const getToken = () =>
    typeof window !== "undefined"
      ? localStorage.getItem("labor_station_token")
      : null;

  const loadProducts = useCallback(async () => {
    const token = getToken();

    if (!token) {
      window.location.href = "/";
      return;
    }

    setLoading(true);
    setError("");
    setMessage("");
    setCart([]);
    setPendingProduct(null);
    setSelectedCategory("Semua");

    try {
      const response = await fetch(
        `${API_URL}/products?sales_channel=${channel}`,
        {
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
          cache: "no-store",
        }
      );

      if (response.status === 401 || response.status === 403) {
        setError("Sesi tidak valid atau akses tidak diizinkan.");
        return;
      }

      const result = await response.json();

      if (!response.ok) {
        throw new Error(
          result.message || "Gagal mengambil katalog produk."
        );
      }

      const productList: Product[] = result.data?.products || [];

      setProducts(productList);

      setCategories([
        "Semua",
        ...Array.from(
          new Set(
            productList
              .map((product) => product.category?.name)
              .filter((name): name is string => Boolean(name))
          )
        ),
      ]);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Terjadi kesalahan koneksi."
      );

      setProducts([]);
    } finally {
      setLoading(false);
    }
  }, [channel]);

  useEffect(() => {
    loadProducts();
  }, [loadProducts]);

  const getPrice = (product: Product) => {
    const price = product.prices?.find(
      (item) => item.sales_channel === channel
    );

    return Number(price?.price || 0);
  };

  const filteredProducts = useMemo(() => {
    return products.filter((product) => {
      const matchSearch = product.name
        .toLowerCase()
        .includes(search.toLowerCase());

      const matchCategory =
        selectedCategory === "Semua" ||
        product.category?.name === selectedCategory;

      return matchSearch && matchCategory;
    });
  }, [products, search, selectedCategory]);

  const addToCart = (
    product: Product,
    temperature: Temperature | null
  ) => {
    if (getPrice(product) <= 0) {
      setError("Produk belum memiliki harga aktif untuk kanal ini.");
      return;
    }

    setError("");
    setMessage("");

    setCart((current) => {
      const existing = current.find(
        (item) =>
          item.product.id === product.id &&
          item.temperature === temperature
      );

      if (existing) {
        return current.map((item) =>
          item.product.id === product.id &&
          item.temperature === temperature
            ? { ...item, quantity: item.quantity + 1 }
            : item
        );
      }

      return [
        ...current,
        {
          product,
          quantity: 1,
          temperature,
        },
      ];
    });

    setPendingProduct(null);
  };

  const handleProductClick = (product: Product) => {
    const options = product.temperature_options ?? [];

    if (options.length > 1) {
      setPendingProduct(product);
      return;
    }

    const temperature = options.length === 1 ? options[0] : null;

    addToCart(product, temperature);
  };

  const changeQuantity = (
    productId: number,
    temperature: Temperature | null,
    amount: number
  ) => {
    setCart((current) =>
      current
        .map((item) =>
          item.product.id === productId &&
          item.temperature === temperature
            ? { ...item, quantity: item.quantity + amount }
            : item
        )
        .filter((item) => item.quantity > 0)
    );
  };

  const removeFromCart = (
    productId: number,
    temperature: Temperature | null
  ) => {
    setCart((current) =>
      current.filter(
        (item) =>
          !(
            item.product.id === productId &&
            item.temperature === temperature
          )
      )
    );
  };

  const totalItems = cart.reduce(
    (total, item) => total + item.quantity,
    0
  );

  const grandTotal = cart.reduce(
    (total, item) =>
      total + getPrice(item.product) * item.quantity,
    0
  );

  const saveTransaction = async () => {
    const token = getToken();

    if (!token) {
      window.location.href = "/";
      return;
    }

    if (cart.length === 0) {
      setError("Keranjang masih kosong.");
      return;
    }

    setSaving(true);
    setError("");
    setMessage("");

    try {
      const response = await fetch(`${API_URL}/transactions`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          customer_name: customerName.trim() || null,
          sales_channel: channel,
          notes: notes.trim() || null,
          items: cart.map((item) => ({
            item_type: "CATALOG",
            product_id: item.product.id,
            quantity: item.quantity,
            temperature: item.temperature,
          })),
        }),
      });

      const result = await response.json();

      if (response.status === 401 || response.status === 403) {
        throw new Error(
          "Sesi tidak valid atau akses tidak diizinkan."
        );
      }

      if (!response.ok) {
        const validationMessage = result.errors
          ? Object.values(result.errors).flat().join(" ")
          : result.message;

        throw new Error(
          validationMessage || "Pesanan gagal disimpan."
        );
      }

      const transaction = result.data?.transaction || result.data;

      setReceipt(transaction);
      setShowReceipt(true);

      const invoice =
        transaction?.invoice_number ||
        transaction?.invoice ||
        transaction?.id ||
        "";

      setMessage(
        invoice
          ? `Pesanan berhasil disimpan. Invoice: ${invoice}`
          : "Pesanan berhasil disimpan."
      );

      setCart([]);
      setCustomerName("");
      setNotes("");
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal menyimpan pesanan."
      );
    } finally {
      setSaving(false);
    }
  };

  // Membuat jendela cetak khusus agar halaman kasir tidak ikut tercetak.
  const printReceipt = (destination: "thermal" | "pdf") => {
    if (!receipt) return;

    const printWindow = window.open(
      "",
      "_blank",
      "width=420,height=720"
    );

    if (!printWindow) {
      setError(
        "Pop-up diblokir. Izinkan pop-up untuk LABOR_STATION."
      );
      return;
    }

    const itemRows = receipt.items
      .map(
        (item) => `
          <div class="item">
            <div class="product">
              ${escapeHtml(item.product_name_snapshot)}
            </div>

            ${
              item.temperature
                ? `
                  <div class="temperature">
                    ${
                      item.temperature === "HOT"
                        ? "Panas (HOT)"
                        : "Dingin (ICED)"
                    }
                  </div>
                `
                : ""
            }

            <div class="item-line">
              <span>
                ${item.quantity} × ${rupiah(item.unit_price)}
              </span>

              <strong>${rupiah(item.subtotal)}</strong>
            </div>
          </div>
        `
      )
      .join("");

    const transactionDate = new Date(
      receipt.created_at
    ).toLocaleString("id-ID", {
      dateStyle: "short",
      timeStyle: "short",
    });

    const customerRow = receipt.customer_name
      ? `
        <div class="meta-row">
          <span>Pelanggan</span>
          <span>${escapeHtml(receipt.customer_name)}</span>
        </div>
      `
      : "";

    const notesRow = receipt.notes
      ? `
        <div class="notes">
          <strong>Catatan:</strong>
          ${escapeHtml(receipt.notes)}
        </div>
      `
      : "";

    printWindow.document.open();

    printWindow.document.write(`
      <!DOCTYPE html>
      <html lang="id">
        <head>
          <meta charset="UTF-8" />
          <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
          />
          <title>Struk LABOR_STATION</title>

          <style>
            @page {
              size: 80mm auto;
              margin: 0;
            }

            * {
              box-sizing: border-box;
            }

            html,
            body {
              width: 80mm;
              margin: 0;
              padding: 0;
              background: #ffffff;
              color: #171717;
              font-family: Arial, Helvetica, sans-serif;
              -webkit-print-color-adjust: exact;
              print-color-adjust: exact;
            }

            #receipt {
              width: 80mm;
              padding: 4mm;
              margin: 0;
              font-size: 10pt;
              line-height: 1.35;
              overflow-wrap: anywhere;
            }

            .center {
              text-align: center;
            }

            .brand {
              margin: 0;
              font-size: 17pt;
              font-weight: 800;
              letter-spacing: 0.5px;
            }

            .subtitle {
              margin: 2mm 0 0;
              font-size: 7pt;
              letter-spacing: 1px;
            }

            .receipt-title {
              margin: 5mm 0 4mm;
              font-size: 9pt;
              font-weight: 700;
            }

            .divider {
              border-top: 1px dashed #777777;
              margin: 3mm 0;
            }

            .meta,
            .totals {
              font-size: 8pt;
            }

            .meta-row,
            .item-line,
            .total-row {
              display: flex;
              justify-content: space-between;
              gap: 3mm;
              margin: 1.5mm 0;
            }

            .meta-row span:last-child,
            .item-line strong,
            .total-row span:last-child {
              text-align: right;
            }

            .section-title {
              margin: 0 0 3mm;
              font-size: 8pt;
              font-weight: 700;
            }

            .item {
              margin: 0 0 3.5mm;
              break-inside: avoid;
            }

            .product {
              font-size: 8.5pt;
              font-weight: 700;
            }

            .temperature {
              margin-top: 1mm;
              font-size: 7pt;
            }

            .item-line {
              margin-top: 1mm;
              font-size: 8pt;
            }

            .total-row.grand {
              margin-top: 3mm;
              font-size: 11pt;
              font-weight: 800;
            }

            .status {
              margin-top: 4mm;
              font-size: 8pt;
              font-weight: 700;
            }

            .notes {
              margin-top: 3mm;
              font-size: 7.5pt;
            }

            .thanks {
              margin-top: 5mm;
              font-size: 7pt;
              text-align: center;
            }

            @media screen {
              html,
              body {
                width: 80mm;
                margin: 0 auto;
                background: #eeeeee;
              }

              #receipt {
                height: auto;
                min-height: 0;
                background: #ffffff;
              }
            }
          </style>
        </head>

        <body>
          <main id="receipt">
            <header class="center">
              <h1 class="brand">LABOR_STATION</h1>

              <p class="subtitle">
                COFFEE STATION MANAGEMENT
              </p>

              <p class="receipt-title">
                STRUK PEMESANAN
              </p>
            </header>

            <div class="divider"></div>

            <section class="meta">
              <div class="meta-row">
                <span>Invoice</span>
                <strong>
                  ${escapeHtml(receipt.invoice_number)}
                </strong>
              </div>

              <div class="meta-row">
                <span>Tanggal</span>
                <span>${escapeHtml(transactionDate)}</span>
              </div>

              <div class="meta-row">
                <span>Channel</span>
                <span>${escapeHtml(receipt.sales_channel)}</span>
              </div>

              ${customerRow}
            </section>

            <div class="divider"></div>

            <section>
              <p class="section-title">
                DETAIL PESANAN
              </p>

              ${itemRows}

              ${notesRow}
            </section>

            <div class="divider"></div>

            <section class="totals">
              <div class="total-row">
                <span>Subtotal</span>
                <span>${rupiah(receipt.subtotal)}</span>
              </div>

              <div class="total-row">
                <span>Diskon</span>
                <span>${rupiah(receipt.discount)}</span>
              </div>

              <div class="total-row grand">
                <span>TOTAL</span>
                <span>${rupiah(receipt.total)}</span>
              </div>
            </section>

            <div class="divider"></div>

            <p class="status center">
              STATUS: ${escapeHtml(receipt.payment_status)}
            </p>

            <footer class="thanks">
              <p>Terima kasih telah memesan.</p>
              <p>Sampai jumpa kembali!</p>
            </footer>
          </main>
        </body>
      </html>
    `);

    printWindow.document.close();

    // PDF dibuat langsung dengan ukuran 80 mm.
    if (destination === "pdf") {
      const receiptElement =
        printWindow.document.getElementById("receipt");

      if (!receiptElement) {
        printWindow.close();
        setError("Gagal menemukan konten struk.");
        return;
      }

      html2canvas(receiptElement, {
        scale: 3,
        backgroundColor: "#ffffff",
        useCORS: true,
        logging: false,
        windowWidth: 400,
      })
        .then((canvas) => {
          const imageData = canvas.toDataURL("image/png");

          const pdfWidth = 80;
          const pdfHeight = (canvas.height * pdfWidth) / canvas.width;

          const pdf = new jsPDF({
            orientation: "portrait",
            unit: "mm",
            format: [pdfWidth, pdfHeight],
            compress: true,
          });

          pdf.addImage(
            imageData,
            "PNG",
            0,
            0,
            pdfWidth,
            pdfHeight,
            undefined,
            "FAST"
          );

          pdf.save(`Struk-${receipt.invoice_number}.pdf`);

          printWindow.close();
        })
        .catch((error) => {
          console.error("Gagal membuat PDF:", error);
          setError("PDF gagal dibuat. Silakan coba kembali.");
          printWindow.close();
        });

      return;
    }

    // Cetak fisik tetap menggunakan dialog printer.
    printWindow.onload = () => {
      printWindow.focus();

      window.setTimeout(() => {
        printWindow.print();
      }, 300);
    };

    printWindow.onafterprint = () => {
      printWindow.close();
    };
  };

  return (
    <>
      <div className="min-h-screen bg-[#f5eee5] text-[#382315]">
<header className="sticky top-0 z-30 border-b border-[#ead9c8] bg-[#fffaf5] px-5 py-4 md:px-8">
  <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
    <div className="flex flex-col items-start gap-3">
      <a
        href="/dashboard"
        className="inline-flex items-center gap-2 rounded-xl border border-[#dfc7b0] bg-white px-4 py-2.5 text-sm font-semibold text-[#543923] transition hover:bg-[#f8eee4]"
      >
        <span>←</span>
        Kembali ke Dashboard
      </a>

      <div>
        <p className="text-xs text-[#a47b5d]">
          Workspace / Transaksi
        </p>

        <h1 className="mt-1 text-xl font-bold">
          Transaksi Kasir
        </h1>
      </div>
    </div>

    <div className="text-left sm:text-right">
      <p className="text-sm font-semibold">
        LABOR_STATION
      </p>

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
              disabled={loading}
              className="rounded-xl border border-[#dfc7b0] bg-white px-5 py-3 text-sm font-semibold hover:bg-[#f8eee4] disabled:opacity-50"
            >
              ↻ Refresh Katalog
            </button>
          </div>

          <section className="mb-6 rounded-2xl border border-[#ead9c8] bg-white p-5 shadow-sm">
            <h3 className="mb-4 font-bold">
              Pilih Kanal Penjualan
            </h3>

            <div className="grid grid-cols-2 gap-3">
              <button
                onClick={() => setChannel("OFFLINE")}
                className={`rounded-xl border p-4 text-left transition ${
                  channel === "OFFLINE"
                    ? "border-[#694329] bg-[#4b2d1c] text-white shadow-md"
                    : "border-[#ead9c8] bg-[#fffaf5] hover:bg-[#f8eee4]"
                }`}
              >
                <span className="text-2xl">☕</span>
                <p className="mt-2 font-bold">OFFLINE</p>
                <p
                  className={`text-xs ${
                    channel === "OFFLINE"
                      ? "text-[#ead4bf]"
                      : "text-[#a47b5d]"
                  }`}
                >
                  Penjualan langsung di kedai
                </p>
              </button>

              <button
                onClick={() => setChannel("GRABFOOD")}
                className={`rounded-xl border p-4 text-left transition ${
                  channel === "GRABFOOD"
                    ? "border-[#694329] bg-[#4b2d1c] text-white shadow-md"
                    : "border-[#ead9c8] bg-[#fffaf5] hover:bg-[#f8eee4]"
                }`}
              >
                <span className="text-2xl">🛵</span>
                <p className="mt-2 font-bold">GRABFOOD</p>
                <p
                  className={`text-xs ${
                    channel === "GRABFOOD"
                      ? "text-[#ead4bf]"
                      : "text-[#a47b5d]"
                  }`}
                >
                  Pesanan melalui GrabFood
                </p>
              </button>
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
              <div className="mb-4 flex flex-col gap-3 sm:flex-row">
                <input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Cari nama produk..."
                  className="min-w-0 flex-1 rounded-xl border border-[#e4d1bd] bg-white px-4 py-3 text-sm outline-none focus:border-[#8c6040]"
                />

                <select
                  value={selectedCategory}
                  onChange={(event) =>
                    setSelectedCategory(event.target.value)
                  }
                  className="rounded-xl border border-[#e4d1bd] bg-white px-4 py-3 text-sm outline-none focus:border-[#8c6040]"
                >
                  {categories.map((category) => (
                    <option key={category} value={category}>
                      {category}
                    </option>
                  ))}
                </select>
              </div>

              <div className="mb-4 flex items-center justify-between">
                <h3 className="text-lg font-bold">
                  Katalog Produk
                </h3>

                <span className="text-xs text-[#9b795e]">
                  {products.length} produk tersedia
                </span>
              </div>

              {loading ? (
                <div className="rounded-2xl bg-white p-10 text-center text-sm text-[#98785e]">
                  Memuat katalog produk...
                </div>
              ) : filteredProducts.length === 0 ? (
                <div className="rounded-2xl border border-[#ead9c8] bg-white p-10 text-center">
                  <p className="text-3xl">☕</p>

                  <p className="mt-3 font-bold">
                    Belum ada produk tersedia
                  </p>

                  <p className="mt-1 text-sm text-[#98785e]">
                    {channel === "GRABFOOD"
                      ? "Belum ada produk dengan harga GrabFood aktif."
                      : "Produk tidak ditemukan atau belum memiliki harga aktif."}
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                  {filteredProducts.map((product) => (
                    <button
                      key={product.id}
                      onClick={() => handleProductClick(product)}
                      className="group rounded-2xl border border-[#ead9c8] bg-white p-4 text-left shadow-sm transition hover:-translate-y-1 hover:border-[#a47b5d] hover:shadow-md"
                    >
                      <div className="flex h-24 items-center justify-center rounded-xl bg-[#f5eee5] text-4xl">
                        ☕
                      </div>

                      <p className="mt-3 line-clamp-2 min-h-10 text-sm font-bold">
                        {product.name}
                      </p>

                      <p className="mt-1 text-xs text-[#a47b5d]">
                        {product.category?.name || "Tanpa kategori"}
                      </p>

                      {product.temperature_options &&
                        product.temperature_options.length > 0 && (
                          <p className="mt-2 text-[10px] font-semibold text-[#8c6040]">
                            {product.temperature_options.join(" / ")}
                          </p>
                        )}

                      <div className="mt-3 flex items-center justify-between gap-2">
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

            <aside className="flex flex-col rounded-2xl border border-[#ead9c8] bg-white p-4 shadow-sm xl:sticky xl:top-24 xl:max-h-[calc(100vh-7rem)]">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-bold">
                  Keranjang Pesanan
                </h3>

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
                  onChange={(event) =>
                    setCustomerName(event.target.value)
                  }
                  placeholder="Nama pelanggan"
                  className="w-full rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm outline-none focus:border-[#8c6040]"
                />
              </div>

              <div className="mt-4">
                <label className="mb-2 block text-xs font-semibold text-[#8c6b51]">
                  Catatan Pesanan
                </label>

                <textarea
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  placeholder="Catatan tambahan (opsional)"
                  rows={2}
                  className="w-full resize-none rounded-xl border border-[#e4d1bd] px-3 py-3 text-sm outline-none focus:border-[#8c6040]"
                />
              </div>

              <div className="my-5 border-t border-dashed border-[#e4d1bd]" />

              <div className="min-h-0 space-y-4 overflow-y-auto xl:flex-1 xl:max-h-none">
                {cart.length === 0 ? (
                  <div className="py-8 text-center">
                    <p className="text-4xl">🛒</p>

                    <p className="mt-3 text-sm font-semibold">
                      Keranjang masih kosong
                    </p>

                    <p className="mt-1 text-xs text-[#a47b5d]">
                      Pilih produk untuk memulai pesanan.
                    </p>
                  </div>
                ) : (
                  cart.map((item) => (
                    <div
                      key={`${item.product.id}-${item.temperature ?? "NONE"}`}
                      className="border-b border-[#f0e6dc] pb-4"
                    >
                      <div className="flex justify-between gap-3">
                        <div className="min-w-0">
                          <p className="text-sm font-bold">
                            {item.product.name}
                          </p>

                          {item.temperature && (
                            <p className="mt-1 text-xs font-semibold text-[#8c6040]">
                              {item.temperature === "HOT"
                                ? "Panas (HOT)"
                                : "Dingin (ICED)"}
                            </p>
                          )}

                          <p className="mt-1 text-xs text-[#98785e]">
                            {rupiah(getPrice(item.product))}
                          </p>
                        </div>

                        <button
                          onClick={() =>
                            removeFromCart(
                              item.product.id,
                              item.temperature
                            )
                          }
                          className="text-xs text-red-500 hover:text-red-700"
                        >
                          Hapus
                        </button>
                      </div>

                      <div className="mt-3 flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <button
                            onClick={() =>
                              changeQuantity(
                                item.product.id,
                                item.temperature,
                                -1
                              )
                            }
                            className="h-8 w-8 rounded-lg border border-[#e4d1bd] font-bold"
                          >
                            −
                          </button>

                          <span className="min-w-5 text-center text-sm font-bold">
                            {item.quantity}
                          </span>

                          <button
                            onClick={() =>
                              changeQuantity(
                                item.product.id,
                                item.temperature,
                                1
                              )
                            }
                            className="h-8 w-8 rounded-lg border border-[#e4d1bd] font-bold"
                          >
                            +
                          </button>
                        </div>

                        <p className="text-sm font-bold">
                          {rupiah(
                            getPrice(item.product) * item.quantity
                          )}
                        </p>
                      </div>
                    </div>
                  ))
                )}
              </div>

              <div className="mt-4 shrink-0 border-t border-[#ead9c8] bg-white pt-4">
                <div className="flex justify-between text-sm text-[#98785e]">
                  <span>Total item</span>
                  <span>{totalItems}</span>
                </div>

                <div className="mt-3 flex items-center justify-between">
                  <span className="font-bold">
                    Total Pembayaran
                  </span>

                  <span className="text-xl font-bold text-[#694329]">
                    {rupiah(grandTotal)}
                  </span>
                </div>

                <button
                  onClick={saveTransaction}
                  disabled={saving || loading || cart.length === 0}
                  className="mt-5 w-full rounded-xl bg-[#4b2d1c] px-4 py-4 text-sm font-bold text-white shadow-md transition hover:bg-[#67432b] disabled:cursor-not-allowed disabled:opacity-40"
                >
                  {saving ? "Menyimpan Pesanan..." : "Simpan Pesanan"}
                </button>

                <p className="mt-3 text-center text-xs text-[#a47b5d]">
                  Pesanan akan disimpan dengan status pembayaran belum dibayar.
                </p>
              </div>
            </aside>
          </div>
        </main>

        {showReceipt && receipt && (
          <div className="fixed inset-0 z-[60] flex items-center justify-center overflow-hidden bg-black/50 p-2 sm:p-4">
            <div className="flex max-h-[calc(100dvh-16px)] w-full max-w-sm flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-h-[calc(100dvh-32px)]">
              {/* Header tetap terlihat ketika isi struk di-scroll */}
              <div className="no-print flex shrink-0 items-center justify-between border-b border-[#ead9c8] px-4 py-3">
                <div>
                  <h2 className="text-lg font-bold text-[#382315]">
                    Transaksi Berhasil
                  </h2>

                  <p className="text-xs text-[#98785e]">
                    Struk pesanan LABOR_STATION
                  </p>
                </div>

                <button
                  onClick={() => setShowReceipt(false)}
                  aria-label="Tutup struk"
                  className="shrink-0 rounded-lg px-3 py-2 text-2xl leading-none text-[#80664e] hover:bg-[#f5eee5]"
                >
                  ×
                </button>
              </div>

              {/* Hanya bagian isi ini yang dapat di-scroll */}
              <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 sm:p-4">
                <div className="mx-auto w-full max-w-[80mm] border border-dashed border-[#d9c5b2] bg-white p-3 text-[#382315]">
                  <div className="text-center">
                    <h2 className="text-xl font-extrabold tracking-wide">
                      LABOR_STATION
                    </h2>

                    <p className="mt-1 text-[10px] tracking-widest text-[#98785e]">
                      COFFEE STATION MANAGEMENT
                    </p>

                    <p className="mt-4 text-xs font-bold">
                      STRUK PEMESANAN
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
                        {new Date(receipt.created_at).toLocaleString(
                          "id-ID",
                          {
                            dateStyle: "short",
                            timeStyle: "short",
                          }
                        )}
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

                  <p className="mb-3 text-xs font-bold">
                    DETAIL PESANAN
                  </p>

                  <div className="space-y-3">
                    {receipt.items.map((item) => (
                      <div key={item.id} className="text-xs">
                        <p className="font-bold">
                          {item.product_name_snapshot}
                        </p>

                        {item.temperature && (
                          <p className="mt-1 text-[10px] font-semibold">
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

                <div className="no-print mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <button
                    onClick={() => printReceipt("thermal")}
                    className="rounded-xl bg-[#4b2d1c] px-4 py-3 text-sm font-bold text-white hover:bg-[#67432b]"
                  >
                    Cetak Thermal 80 mm
                  </button>

                  <button
                    onClick={() => printReceipt("pdf")}
                    className="rounded-xl border border-[#dfc7b0] bg-[#fffaf5] px-4 py-3 text-sm font-bold text-[#694329] hover:bg-[#f5eee5]"
                  >
                    Simpan PDF 80 mm
                  </button>

                  <button
                    onClick={() => setShowReceipt(false)}
                    className="rounded-xl border border-[#dfc7b0] px-4 py-3 text-sm font-semibold text-[#694329] hover:bg-[#f5eee5] sm:col-span-2"
                  >
                    Tutup
                  </button>
                </div>

                <p className="no-print mt-3 text-center text-xs text-[#98785e]">
                  Pilih printer thermal untuk cetak fisik atau Microsoft Print to PDF untuk menyimpan PDF.
                </p>
              </div>
            </div>
          </div>
        )}

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
                    className="rounded-xl border border-[#dfc7b0] bg-white p-4 font-bold text-[#543923] transition hover:border-[#694329] hover:bg-[#f3e5d6]"
                  >
                    <span className="block text-2xl">♨️</span>
                    <span className="mt-2 block">HOT</span>
                    <span className="mt-1 block text-xs font-normal text-[#98785e]">
                      Panas
                    </span>
                  </button>
                )}

                {pendingProduct.temperature_options?.includes("ICED") && (
                  <button
                    onClick={() => addToCart(pendingProduct, "ICED")}
                    className="rounded-xl border border-[#dfc7b0] bg-white p-4 font-bold text-[#543923] transition hover:border-[#694329] hover:bg-[#f3e5d6]"
                  >
                    <span className="block text-2xl">🧊</span>
                    <span className="mt-2 block">ICED</span>
                    <span className="mt-1 block text-xs font-normal text-[#98785e]">
                      Dingin
                    </span>
                  </button>
                )}
              </div>

              <button
                onClick={() => setPendingProduct(null)}
                className="mt-4 w-full rounded-xl border border-[#e4d1bd] px-4 py-3 text-sm font-semibold text-[#80664e] transition hover:bg-[#f5eee5]"
              >
                Batal
              </button>
            </div>
          </div>
        )}
      </div>
    </>
  );
}