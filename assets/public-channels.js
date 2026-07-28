(function () {
  "use strict";

  if (window.__TropikalPublicComponentsLoaded) return;
  window.__TropikalPublicComponentsLoaded = true;

  const script = document.currentScript;
  if (!script) return;

  const ownUrl = new URL(script.src, window.location.href);
  const version = ownUrl.searchParams.get("v") || "unversioned";
  const root = "/tropikal-connect";

  function json(response) {
    return response.json().catch(() => ({})).then((body) => ({ response, body }));
  }

  async function installChat() {
    try {
      const result = await fetch(`${root}/api/chat/info`, {
        credentials: "omit",
        headers: { Accept: "application/json" },
      }).then(json);
      if (!result.response.ok || !result.body.channel_id) return;

      window.__TropikalChatWidgetBootstrap = {
        origin: window.location.origin,
        info: result.body,
      };
      if (document.querySelector("script[data-tropikal-chat-widget]")) return;
      const widget = document.createElement("script");
      widget.async = true;
      widget.dataset.tropikalChatWidget = "true";
      widget.src = `${root}/embed/chat-widget.js?v=${encodeURIComponent(version)}`;
      document.head.appendChild(widget);
    } catch {
      // Inactive or unavailable Chat is deliberately invisible to visitors.
    }
  }

  function loadStyles() {
    if (document.querySelector("link[data-tropikal-public-channels]")) return;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.dataset.tropikalPublicChannels = "true";
    link.href = `${root}/assets/public-channels.css?v=${encodeURIComponent(version)}`;
    document.head.appendChild(link);
  }

  function bookingUuid(slot) {
    const key = `tropikal-booking:${slot.start}`;
    let value = sessionStorage.getItem(key);
    if (!value) {
      value = globalThis.crypto && typeof globalThis.crypto.randomUUID === "function"
        ? `booking_${globalThis.crypto.randomUUID()}`
        : `booking_${Date.now()}_${Math.random().toString(16).slice(2)}`;
      sessionStorage.setItem(key, value);
    }
    return { key, value };
  }

  function resetTurnstile(host) {
    host.dataset.turnstileToken = "";
    const widgetId = host.dataset.turnstileWidgetId;
    if (widgetId && window.turnstile) window.turnstile.reset(widgetId);
  }

  function renderTurnstile(host) {
    const siteKey = host.dataset.turnstileSiteKey || "";
    if (!siteKey) return;
    const target = host.querySelector("[data-booking-turnstile]");
    if (!target) return;

    const mount = () => {
      if (!window.turnstile || host.dataset.turnstileWidgetId) return;
      host.dataset.turnstileWidgetId = String(window.turnstile.render(target, {
        sitekey: siteKey,
        theme: "auto",
        callback: (token) => { host.dataset.turnstileToken = token; },
        "expired-callback": () => { host.dataset.turnstileToken = ""; },
        "error-callback": () => { host.dataset.turnstileToken = ""; },
      }));
    };
    if (window.turnstile) {
      mount();
      return;
    }
    if (!document.querySelector("script[data-tropikal-turnstile]")) {
      const challenge = document.createElement("script");
      challenge.async = true;
      challenge.defer = true;
      challenge.dataset.tropikalTurnstile = "true";
      challenge.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      challenge.addEventListener("load", mount, { once: true });
      document.head.appendChild(challenge);
    } else {
      const wait = window.setInterval(() => {
        if (!window.turnstile) return;
        window.clearInterval(wait);
        mount();
      }, 100);
      window.setTimeout(() => window.clearInterval(wait), 10000);
    }
  }

  function slotLabel(slot) {
    const start = new Date(slot.start);
    const end = new Date(slot.end);
    return `${new Intl.DateTimeFormat(undefined, {
      weekday: "short",
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    }).format(start)} – ${new Intl.DateTimeFormat(undefined, {
      hour: "numeric",
      minute: "2-digit",
    }).format(end)}`;
  }

  function safeHttpsUrl(value) {
    try {
      const url = new URL(String(value || ""));
      return url.protocol === "https:" ? url.href : "";
    } catch {
      return "";
    }
  }

  async function pollBooking(host, bookingId) {
    for (let attempt = 0; attempt < 12; attempt += 1) {
      await new Promise((resolve) => window.setTimeout(resolve, 2500));
      const result = await fetch(
        `${root}/api/booking/status?booking_uuid=${encodeURIComponent(bookingId)}`,
        { credentials: "omit", headers: { Accept: "application/json" } },
      ).then(json);
      if (result.response.status === 200 && result.body.status === "confirmed") return result.body;
      if (result.response.status !== 202) throw new Error(result.body.message || "Booking status is unavailable.");
    }
    throw new Error("The booking is still being confirmed. Keep this page open and try again.");
  }

  function bookingForm(host, slot) {
    host.innerHTML = `
      <section class="tk-booking-card" aria-labelledby="tk-booking-title">
        <div class="tk-booking-eyebrow">Book a call</div>
        <h2 id="tk-booking-title">Confirm your time</h2>
        <p class="tk-booking-selection"></p>
        <form class="tk-booking-form">
          <label>Name<input name="name" autocomplete="name" maxlength="200" required></label>
          <label>Email<input name="email" type="email" autocomplete="email" maxlength="320" required></label>
          <label>Phone<input name="phone" type="tel" autocomplete="tel" maxlength="60" required></label>
          <label>Anything we should know?<textarea name="note" maxlength="2000" rows="3"></textarea></label>
          <div data-booking-turnstile></div>
          <p class="tk-booking-message" role="status" aria-live="polite"></p>
          <div class="tk-booking-actions">
            <button type="button" class="tk-booking-secondary" data-booking-back>Choose another time</button>
            <button type="submit" class="tk-booking-primary">Confirm booking</button>
          </div>
        </form>
      </section>`;
    host.querySelector(".tk-booking-selection").textContent = slotLabel(slot);
    host.querySelector("[data-booking-back]").addEventListener("click", () => mountBooking(host));
    renderTurnstile(host);

    host.querySelector("form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector("[type=submit]");
      const message = form.querySelector(".tk-booking-message");
      const attempt = bookingUuid(slot);
      submit.disabled = true;
      message.textContent = "Confirming your booking…";

      const data = new FormData(form);
      const payload = {
        name: data.get("name"),
        email: data.get("email"),
        phone: data.get("phone"),
        note: data.get("note"),
        slot_start: slot.start,
        slot_end: slot.end,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
        booking_uuid: attempt.value,
        cf_turnstile_token: host.dataset.turnstileToken || "",
      };

      try {
        const result = await fetch(`${root}/api/booking/intro-call`, {
          method: "POST",
          credentials: "omit",
          headers: { Accept: "application/json", "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        }).then(json);
        let body = result.body;
        if (result.response.status === 202) body = await pollBooking(host, attempt.value);
        if (body.status !== "confirmed") throw new Error(body.reason || body.message || "That time is no longer available.");

        sessionStorage.removeItem(attempt.key);
        host.innerHTML = `
          <section class="tk-booking-card tk-booking-success" role="status">
            <div class="tk-booking-eyebrow">Booked</div>
            <h2>You’re all set.</h2>
            <p>Your Google Calendar invitation contains the call details.</p>
            <p data-booking-meeting></p>
          </section>`;
        const meetingUrl = safeHttpsUrl(body.meet_url);
        const meetingHost = host.querySelector("[data-booking-meeting]");
        if (meetingUrl && meetingHost) {
          const link = document.createElement("a");
          link.className = "tk-booking-primary";
          link.href = meetingUrl;
          link.target = "_blank";
          link.rel = "noopener";
          link.textContent = "Open meeting";
          meetingHost.appendChild(link);
        } else if (meetingHost) {
          meetingHost.remove();
        }
      } catch (error) {
        message.textContent = error instanceof Error ? error.message : "The booking could not be completed.";
        resetTurnstile(host);
        submit.disabled = false;
      }
    });
  }

  async function mountBooking(host) {
    loadStyles();
    host.innerHTML = `
      <section class="tk-booking-card" aria-busy="true">
        <div class="tk-booking-eyebrow">Book a call</div>
        <h2>Choose a time</h2>
        <p class="tk-booking-message" role="status">Loading available times…</p>
      </section>`;

    const from = new Date();
    const to = new Date(from);
    to.setDate(to.getDate() + 30);
    const date = (value) => value.toISOString().slice(0, 10);

    try {
      const result = await fetch(
        `${root}/api/booking/availability?from=${date(from)}&to=${date(to)}`,
        { credentials: "omit", headers: { Accept: "application/json" } },
      ).then(json);
      if (!result.response.ok || !Array.isArray(result.body.slots)) {
        throw new Error("Available times are temporarily unavailable.");
      }

      host.innerHTML = `
        <section class="tk-booking-card" aria-labelledby="tk-booking-title">
          <div class="tk-booking-eyebrow">Book a call</div>
          <h2 id="tk-booking-title">Choose a time</h2>
          <p>Times are shown in your timezone.</p>
          <div class="tk-booking-slots" role="list"></div>
          <p class="tk-booking-message" role="status" aria-live="polite"></p>
        </section>`;
      const slots = host.querySelector(".tk-booking-slots");
      if (result.body.slots.length === 0) {
        host.querySelector(".tk-booking-message").textContent = "There are no open times in this window.";
        return;
      }
      result.body.slots.forEach((slot) => {
        if (!slot || !slot.start || !slot.end || new Date(slot.start) <= new Date()) return;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "tk-booking-slot";
        button.setAttribute("role", "listitem");
        button.textContent = slotLabel(slot);
        button.addEventListener("click", () => bookingForm(host, slot));
        slots.appendChild(button);
      });
      if (!slots.children.length) {
        host.querySelector(".tk-booking-message").textContent = "There are no open times in this window.";
      }
    } catch (error) {
      host.querySelector(".tk-booking-message").textContent =
        error instanceof Error ? error.message : "Available times are temporarily unavailable.";
    }
  }

  function boot() {
    void installChat();
    document.querySelectorAll("[data-tropikal-booking]").forEach((host) => void mountBooking(host));
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
}());
