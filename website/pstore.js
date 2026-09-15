window.PKS = (function() {
  var prefs = (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform() && window.Capacitor.Preferences)
    ? window.Capacitor.Preferences
    : null;
  var mem = null;
  var hydrating = false;

  function load() {
    if (mem) return Promise.resolve(mem);
    if (hydrating) return loadPromise;
    if (!prefs) { mem = {}; return Promise.resolve(mem); }
    hydrating = true;
    loadPromise = prefs.get({ key: 'pk_store' }).then(function(r) {
      mem = {};
      try { if (r && r.value) mem = JSON.parse(r.value); } catch (e) { mem = {}; }
      return mem;
    }).catch(function() { mem = {}; return mem; });
    return loadPromise;
  }

  function flush() {
    if (!prefs || !mem) return;
    prefs.set({ key: 'pk_store', value: JSON.stringify(mem) }).catch(function() {});
  }

  return {
    isNative: !!prefs,
    init: function() {
      return load().then(function(m) {
        for (var k in m) {
          try { localStorage.setItem(k, m[k]); } catch (e) {}
        }
        return m;
      });
    },
    set: function(key, val) {
      try { localStorage.setItem(key, val); } catch (e) {}
      if (prefs) {
        load().then(function(m) {
          m[key] = val;
          flush();
        });
      }
    },
    remove: function(key) {
      try { localStorage.removeItem(key); } catch (e) {}
      if (prefs) {
        load().then(function(m) {
          delete m[key];
          flush();
        });
      }
    },
    clear: function() {
      try { localStorage.clear(); } catch (e) {}
      if (prefs) {
        load().then(function(m) {
          mem = {};
          prefs.remove({ key: 'pk_store' }).catch(function() {});
        });
      }
    }
  };
})();