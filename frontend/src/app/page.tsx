"use client";

import { useState, type FormEvent } from "react";
import Image from "next/image";

const API_URL = (
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api"
).replace(/\/$/, "");

export default function Home() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setLoading(true);

    try {
      const response = await fetch(`${API_URL}/login`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ email, password }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(
          data.message ||
          data.errors?.email?.[0] ||
          "Login gagal. Periksa email dan password."
        );
      }

      if (!data.token) {
        throw new Error("Token login tidak ditemukan.");
      }

      localStorage.setItem("labor_station_token", data.token);
      localStorage.setItem(
        "labor_station_user",
        JSON.stringify(data.user)
      );

      window.location.href = "/dashboard";
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Tidak dapat terhubung ke server."
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="login-page relative flex min-h-[100svh] items-center justify-center overflow-x-hidden px-4 py-5 sm:px-6 lg:px-10 lg:py-8">

      {/* Background overlay */}
      <div className="absolute inset-0 bg-[#21140f]/25" />
      <div className="absolute inset-0 bg-gradient-to-r from-[#21140f]/35 via-transparent to-[#21140f]/20" />

      <div className="relative z-10 grid w-full max-w-[1350px] items-center gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:gap-12">


        {/* LEFT BRANDING PANEL */}
        <section className="hidden px-4 py-10 text-[#fff5e9] lg:block xl:px-8 lg:translate-y-[225px]">

          <p className="mb-4 text-[11px] font-bold uppercase tracking-[0.35em] text-[#f4c28e]">
            Welcome to
          </p>

          <h1 className="max-w-lg text-2xl font-extrabold leading-tight tracking-tight drop-shadow-[0_3px_8px_rgba(0,0,0,0.45)] xl:text-3xl">
            Manage Your
            <span className="block text-[#f5c99d]">
              Coffee Station
            </span>
          </h1>

          <div className="mt-5 h-1 w-16 rounded-full bg-[#e7b47e]" />

          <p className="mt-6 max-w-md text-[13px] leading-7 text-[#fff0df] drop-shadow-[0_2px_5px_rgba(0,0,0,0.8)] xl:text-sm">
            Kelola transaksi, produk, persediaan,
            dan laporan bisnis dengan mudah
            melalui satu sistem yang terintegrasi.
          </p>

          <div className="mt-8 flex items-center gap-3 text-[11px] text-[#f5d9ba]">
            <span className="h-px w-9 bg-[#e7b47e]" />
            <span className="tracking-[0.22em]">
              BREW • SERVE • MANAGE
            </span>
          </div>

        </section>


        {/* LOGIN GLASS PANEL */}
        <section className="glass-panel mx-auto w-full max-w-[520px] rounded-[26px] border border-white/60 p-6 shadow-[0_25px_80px_rgba(42,24,12,0.35)] backdrop-blur-xl sm:rounded-[30px] sm:p-9 lg:p-10">

          {/* Single Logo */}
          <div className="mb-7 text-center">
            <div className="mx-auto mb-4 flex h-[76px] w-[76px] items-center justify-center overflow-hidden rounded-[21px] bg-black shadow-[0_8px_25px_rgba(54,32,18,0.25)] sm:h-[88px] sm:w-[88px]">
              <Image
                src="/labor-station-logo.jpeg"
                alt="Logo LABOR_STATION"
                width={120}
                height={120}
                priority
                className="h-full w-full object-cover"
              />
            </div>

            <h2 className="text-xl font-black tracking-wide text-[#28190f] sm:text-2xl">
              LABOR_STATION
            </h2>

            <p className="mt-2 text-xs font-medium text-[#76583f] sm:text-sm">
              Coffee Station Management System
            </p>

            <div className="mx-auto mt-5 flex max-w-[270px] items-center gap-3">
              <span className="h-px flex-1 bg-[#b99b7e]" />
              <span className="text-[10px] tracking-[0.2em] text-[#8a6a4e] sm:text-xs">
                OWNER ACCESS
              </span>
              <span className="h-px flex-1 bg-[#b99b7e]" />
            </div>
          </div>

          {/* Welcome */}
          <div className="mb-6">
            <h3 className="text-2xl font-extrabold text-[#28190f] sm:text-3xl">
              Welcome Back
            </h3>

            <p className="mt-2 text-xs text-[#76583f] sm:text-sm">
              Sign in to manage your coffee station.
            </p>
          </div>

          {/* Login Form */}
          <form onSubmit={handleLogin} className="space-y-5">

            {/* Email */}
            <div>
              <label
                htmlFor="email"
                className="mb-2 block text-sm font-bold text-[#392619]"
              >
                Email Address
              </label>

              <div className="coffee-input flex items-center gap-3 rounded-2xl border border-[#98785a]/70 px-3 sm:px-4">
                <span className="text-lg text-[#543923]">✉</span>
                <span className="h-7 w-px bg-[#b99b7e]" />

                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="owner@laborstation.local"
                  autoComplete="username"
                  required
                  className="w-full min-w-0 bg-transparent py-4 text-sm text-[#28190f] outline-none placeholder:text-[#967d67]"
                />
              </div>
            </div>

            {/* Password */}
            <div>
              <div className="mb-2 flex items-center justify-between">
                <label
                  htmlFor="password"
                  className="block text-sm font-bold text-[#392619]"
                >
                  Password
                </label>

                <span className="text-[10px] font-semibold text-[#896444] sm:text-xs">
                  Secure Access
                </span>
              </div>

              <div className="coffee-input flex items-center gap-3 rounded-2xl border border-[#98785a]/70 px-3 sm:px-4">
                <span className="text-lg text-[#543923]">♙</span>
                <span className="h-7 w-px bg-[#b99b7e]" />

                <input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="Enter your password"
                  autoComplete="current-password"
                  required
                  className="w-full min-w-0 bg-transparent py-4 text-sm text-[#28190f] outline-none placeholder:text-[#967d67]"
                />

                {/* Eye button */}
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label={
                    showPassword
                      ? "Sembunyikan password"
                      : "Tampilkan password"
                  }
                  title={
                    showPassword
                      ? "Sembunyikan password"
                      : "Tampilkan password"
                  }
                  className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#76583f] transition hover:bg-[#98785a]/10 hover:text-[#28190f]"
                >
                  {showPassword ? (
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="1.8"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      className="h-5 w-5"
                    >
                      <path d="M3 3l18 18" />
                      <path d="M10.6 10.6a2 2 0 002.8 2.8" />
                      <path d="M9.9 5.2A10.8 10.8 0 0112 5c5 0 8.5 4.5 9.5 7-.4 1-1.2 2.1-2.2 3" />
                      <path d="M6.2 6.2C3.9 7.6 2.8 10 2.5 12c1 2.5 4.5 7 9.5 7 1 0 2-.2 2.9-.5" />
                    </svg>
                  ) : (
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="1.8"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      className="h-5 w-5"
                    >
                      <path d="M2.5 12s3.5-7 9.5-7 9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg>
                  )}
                </button>
              </div>
            </div>

            {/* Error */}
            {error && (
              <div
                role="alert"
                className="rounded-xl border border-red-300 bg-red-50/90 p-3 text-sm text-red-700"
              >
                {error}
              </div>
            )}

            {/* Submit */}
            <button
              type="submit"
              disabled={loading}
              className="coffee-button w-full rounded-2xl py-4 text-sm font-bold tracking-wide text-white shadow-[0_10px_25px_rgba(64,39,25,0.25)] transition duration-300 hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? "Signing in..." : "Sign In"}
              {!loading && <span className="ml-3">→</span>}
            </button>
          </form>

          {/* Footer */}
          <div className="mt-7 flex items-center gap-3">
            <span className="h-px flex-1 bg-[#b99b7e]/70" />
            <span className="text-[10px] font-medium text-[#896c53] sm:text-xs">
              LABOR_STATION
            </span>
            <span className="h-px flex-1 bg-[#b99b7e]/70" />
          </div>

          <p className="mt-5 text-center text-[10px] leading-5 text-[#80664e] sm:text-xs">
            Authorized access only. Your coffee business,
            managed in one place.
          </p>
        </section>
      </div>
    </main>
  );
}