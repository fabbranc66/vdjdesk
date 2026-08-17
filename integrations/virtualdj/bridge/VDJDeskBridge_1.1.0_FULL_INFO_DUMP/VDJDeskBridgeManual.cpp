#include "VDJDeskBridgeManual.h"

#include <windows.h>
#include <chrono>
#include <fstream>
#include <iomanip>
#include <sstream>
#include <vector>

static std::string jsonEscape(const std::string& s)
{
    std::ostringstream out;
    for (unsigned char c : s)
    {
        switch (c)
        {
            case '\\': out << "\\\\"; break;
            case '"':  out << "\\\""; break;
            case '\b': out << "\\b"; break;
            case '\f': out << "\\f"; break;
            case '\n': out << "\\n"; break;
            case '\r': out << "\\r"; break;
            case '\t': out << "\\t"; break;
            default:
                if (c < 0x20)
                {
                    out << "\\u"
                        << std::hex << std::uppercase
                        << std::setw(4) << std::setfill('0')
                        << static_cast<int>(c);
                }
                else
                {
                    out << static_cast<char>(c);
                }
        }
    }
    return out.str();
}

static std::string nowText()
{
    SYSTEMTIME st{};
    GetLocalTime(&st);

    std::ostringstream s;
    s << st.wYear << "-"
      << std::setw(2) << std::setfill('0') << st.wMonth << "-"
      << std::setw(2) << std::setfill('0') << st.wDay << "T"
      << std::setw(2) << std::setfill('0') << st.wHour << ":"
      << std::setw(2) << std::setfill('0') << st.wMinute << ":"
      << std::setw(2) << std::setfill('0') << st.wSecond;
    return s.str();
}

static bool loadedTrack(const std::string& title, const std::string& filepath)
{
    if (title.empty() || filepath.empty())
        return false;

    if (title.find("Trascina un brano") != std::string::npos)
        return false;

    return true;
}

std::string CVDJDeskBridgeManual::baseDir() const
{
    char local[MAX_PATH] = {};
    DWORD n = GetEnvironmentVariableA("LOCALAPPDATA", local, MAX_PATH);

    std::string base = (n > 0 && n < MAX_PATH) ? local : ".";
    std::string dir = base + "\\VirtualDJ";
    CreateDirectoryA(dir.c_str(), nullptr);
    return dir;
}

std::string CVDJDeskBridgeManual::logPath() const
{
    return baseDir() + "\\VDJDeskBridge.log";
}

std::string CVDJDeskBridgeManual::currentLogPath() const
{
    return baseDir() + "\\VDJDeskBridgeCurrent.log";
}

std::string CVDJDeskBridgeManual::statePath() const
{
    return baseDir() + "\\VDJDeskBridgeState.json";
}

std::string CVDJDeskBridgeManual::getStringSafe(const std::string& command)
{
    char buf[32768] = {};
    HRESULT hr = GetStringInfo(command.c_str(), buf, static_cast<int>(sizeof(buf)));
    return hr == S_OK ? std::string(buf) : std::string();
}

double CVDJDeskBridgeManual::getNumberSafe(
    const std::string& command,
    double fallback)
{
    double value = fallback;
    HRESULT hr = GetInfo(command.c_str(), &value);
    return hr == S_OK ? value : fallback;
}

bool CVDJDeskBridgeManual::getBoolSafe(const std::string& command)
{
    return getNumberSafe(command, 0.0) != 0.0;
}

CVDJDeskBridgeManual::DeckState CVDJDeskBridgeManual::readDeck(int deck)
{
    DeckState d;
    d.deck = deck;

    const std::string p = "deck " + std::to_string(deck) + " ";

    d.title = getStringSafe(p + "get_title");
    d.artist = getStringSafe(p + "get_artist");
    d.filepath = getStringSafe(p + "get_filepath");
    d.bpm = getNumberSafe(p + "get_bpm", 0.0);
    d.position = getNumberSafe(p + "get_position", 0.0);
    d.hasLyrics = getBoolSafe(p + "has_lyrics");
    d.lyricsLanguage = getStringSafe(p + "get_lyrics_language");

    return d;
}

std::string CVDJDeskBridgeManual::signature(const DeckState& d) const
{
    // filepath is the primary identity; title included as fallback.
    return d.filepath + "\n" + d.title;
}

void CVDJDeskBridgeManual::writeState(
    const DeckState& d1,
    const DeckState& d2)
{
    const std::string tmp = statePath() + ".tmp";
    std::ofstream f(tmp, std::ios::binary | std::ios::trunc);
    if (!f)
        return;

    auto writeDeck = [&](const DeckState& d, bool comma)
    {
        const bool loaded = loadedTrack(d.title, d.filepath);

        f << "    {\r\n";
        f << "      \"deck\": " << d.deck << ",\r\n";
        f << "      \"loaded\": " << (loaded ? "true" : "false") << ",\r\n";
        f << "      \"title\": \"" << jsonEscape(d.title) << "\",\r\n";
        f << "      \"artist\": \"" << jsonEscape(d.artist) << "\",\r\n";
        f << "      \"filepath\": \"" << jsonEscape(d.filepath) << "\",\r\n";
        f << "      \"bpm\": " << std::setprecision(15) << d.bpm << ",\r\n";
        f << "      \"position\": " << std::setprecision(15) << d.position << ",\r\n";
        f << "      \"hasLyrics\": " << (d.hasLyrics ? "true" : "false") << ",\r\n";
        f << "      \"lyricsLanguage\": \"" << jsonEscape(d.lyricsLanguage) << "\"\r\n";
        f << "    }";
        if (comma) f << ",";
        f << "\r\n";
    };

    f << "{\r\n";
    f << "  \"bridgeVersion\": \"0.2.7-manual-active\",\r\n";
    f << "  \"mode\": \"manual-active-watch\",\r\n";
    f << "  \"capturedAt\": \"" << nowText() << "\",\r\n";
    f << "  \"decks\": [\r\n";
    writeDeck(d1, true);
    writeDeck(d2, false);
    f << "  ]\r\n";
    f << "}\r\n";
    f.flush();
    f.close();

    DeleteFileA(statePath().c_str());
    MoveFileA(tmp.c_str(), statePath().c_str());
}

static void ensureBom(const std::string& path)
{
    std::ifstream in(path, std::ios::binary);
    bool needs = true;

    if (in)
    {
        unsigned char b[3] = {};
        in.read(reinterpret_cast<char*>(b), 3);
        if (in.gcount() == 3 &&
            b[0] == 0xEF && b[1] == 0xBB && b[2] == 0xBF)
            needs = false;
    }

    if (!needs)
        return;

    std::string old;
    {
        std::ifstream prev(path, std::ios::binary);
        if (prev)
        {
            std::ostringstream ss;
            ss << prev.rdbuf();
            old = ss.str();
        }
    }

    std::ofstream out(path, std::ios::binary | std::ios::trunc);
    if (!out)
        return;

    const unsigned char bom[3] = {0xEF,0xBB,0xBF};
    out.write(reinterpret_cast<const char*>(bom),3);
    out.write(old.data(), static_cast<std::streamsize>(old.size()));
}

void CVDJDeskBridgeManual::writeHistoricalLog(
    const DeckState& d1,
    const DeckState& d2)
{
    std::ofstream f(logPath(), std::ios::binary | std::ios::trunc);
    if (!f)
        return;

    const unsigned char bom[3] = {0xEF,0xBB,0xBF};
    f.write(reinterpret_cast<const char*>(bom),3);

    auto deck = [&](const DeckState& d)
    {
        f << "DECK " << d.deck << "\r\n";
        f << "title=" << d.title << "\r\n";
        f << "artist=" << d.artist << "\r\n";
        f << "filepath=" << d.filepath << "\r\n";
        f << "bpm=" << std::setprecision(15) << d.bpm << "\r\n";
        f << "position=" << std::setprecision(15) << d.position << "\r\n";
        f << "hasLyrics=" << (d.hasLyrics ? "true" : "false") << "\r\n";
        f << "lyricsLanguage=" << d.lyricsLanguage << "\r\n";
    };

    deck(d1);
    f << "\r\n";
    deck(d2);
    f.flush();
}

void CVDJDeskBridgeManual::writeCurrentLog(
    const DeckState& d1,
    const DeckState& d2)
{
    std::ofstream f(currentLogPath(), std::ios::binary | std::ios::trunc);
    if (!f)
        return;

    const unsigned char bom[3] = {0xEF,0xBB,0xBF};
    f.write(reinterpret_cast<const char*>(bom),3);

    auto deck = [&](const DeckState& d)
    {
        f << "DECK " << d.deck << "\r\n";
        f << "title=" << d.title << "\r\n";
        f << "artist=" << d.artist << "\r\n";
        f << "filepath=" << d.filepath << "\r\n";
        f << "bpm=" << std::setprecision(15) << d.bpm << "\r\n";
        f << "position=" << std::setprecision(15) << d.position << "\r\n";
        f << "hasLyrics=" << (d.hasLyrics ? "true" : "false") << "\r\n";
        f << "lyricsLanguage=" << d.lyricsLanguage << "\r\n";
    };

    deck(d1);
    f << "\r\n";
    deck(d2);
    f.flush();
}

void CVDJDeskBridgeManual::processIfChanged(bool force)
{
    DeckState d1 = readDeck(1);
    DeckState d2 = readDeck(2);

    const std::string s1 = signature(d1);
    const std::string s2 = signature(d2);

    if (!force && s1 == lastSignature1_ && s2 == lastSignature2_)
        return;

    lastSignature1_ = s1;
    lastSignature2_ = s2;

    std::lock_guard<std::mutex> lock(ioMutex_);
    writeState(d1,d2);
    writeCurrentLog(d1,d2);
    writeHistoricalLog(d1,d2);
}

void CVDJDeskBridgeManual::watcherLoop()
{
    // Immediate state when the plugin is switched ON.
    processIfChanged(true);

    while (running_)
    {
        // Same lightweight concept as the previously working bridge:
        // only query VirtualDJ state, no filesystem/audio analysis here.
        std::this_thread::sleep_for(std::chrono::milliseconds(750));

        if (!running_)
            break;

        processIfChanged(false);
    }
}


std::string CVDJDeskBridgeManual::pluginDirectory() const
{
    char path[MAX_PATH] = {};

    // hInstance viene fornito direttamente da VirtualDJ al plugin.
    // Non serve ricavare il modulo tramite un indirizzo di funzione.
    DWORD n = GetModuleFileNameA(hInstance, path, MAX_PATH);

    if (n == 0 || n >= MAX_PATH)
        return "";

    std::string full(path, n);
    size_t slash = full.find_last_of("\\/");

    if (slash == std::string::npos)
        return "";

    return full.substr(0, slash);
}

bool CVDJDeskBridgeManual::startAnalyzer()
{
    if (analyzerProcess_.hProcess)
        return true;

    const std::string dir = pluginDirectory();
    if (dir.empty())
        return false;

    const std::string script = dir + "\\VDJDeskBridge\\Analyzer\\VDJDesk_AutoVocalCue.py";

    DWORD attrs = GetFileAttributesA(script.c_str());
    if (attrs == INVALID_FILE_ATTRIBUTES || (attrs & FILE_ATTRIBUTE_DIRECTORY))
        return false;

    char pyLauncher[MAX_PATH] = {};
    DWORD pyLen = SearchPathA(nullptr, "py.exe", nullptr, MAX_PATH, pyLauncher, nullptr);

    std::string exe;
    std::string args;

    if (pyLen > 0 && pyLen < MAX_PATH)
    {
        exe = pyLauncher;
        args = "\"" + exe + "\" -3 \"" + script + "\" --parent-pid " +
               std::to_string(GetCurrentProcessId());
    }
    else
    {
        char pythonExe[MAX_PATH] = {};
        DWORD pLen = SearchPathA(nullptr, "python.exe", nullptr, MAX_PATH, pythonExe, nullptr);

        if (pLen == 0 || pLen >= MAX_PATH)
            return false;

        exe = pythonExe;
        args = "\"" + exe + "\" \"" + script + "\" --parent-pid " +
               std::to_string(GetCurrentProcessId());
    }

    std::vector<char> cmd(args.begin(), args.end());
    cmd.push_back('\0');

    STARTUPINFOA si{};
    si.cb = sizeof(si);

    PROCESS_INFORMATION pi{};

    BOOL ok = CreateProcessA(
        exe.c_str(),
        cmd.data(),
        nullptr,
        nullptr,
        FALSE,
        CREATE_NO_WINDOW,
        nullptr,
        dir.c_str(),
        &si,
        &pi
    );

    if (!ok)
        return false;

    analyzerProcess_ = pi;

    if (analyzerProcess_.hThread)
    {
        CloseHandle(analyzerProcess_.hThread);
        analyzerProcess_.hThread = nullptr;
    }

    return true;
}

void CVDJDeskBridgeManual::stopAnalyzer()
{
    if (!analyzerProcess_.hProcess)
        return;

    DWORD exitCode = 0;

    if (GetExitCodeProcess(analyzerProcess_.hProcess, &exitCode) &&
        exitCode == STILL_ACTIVE)
    {
        TerminateProcess(analyzerProcess_.hProcess, 0);
        WaitForSingleObject(analyzerProcess_.hProcess, 2000);
    }

    CloseHandle(analyzerProcess_.hProcess);
    analyzerProcess_.hProcess = nullptr;
    analyzerProcess_.dwProcessId = 0;
}

HRESULT VDJ_API CVDJDeskBridgeManual::OnLoad()
{
    // Plugin loaded by VDJ, but inactive: no watcher here.
    return S_OK;
}

HRESULT VDJ_API CVDJDeskBridgeManual::OnGetPluginInfo(TVdjPluginInfo8 *infos)
{
    infos->PluginName = "VDJDesk Bridge";
    infos->Author = "VDJDesk";
    infos->Description = "VDJDesk bridge + automatic vocal stem analyzer while active";
    infos->Version = "1.1.0";
    infos->Flags = 0;
    infos->Bitmap = nullptr;
    return S_OK;
}

ULONG VDJ_API CVDJDeskBridgeManual::Release()
{
    running_ = false;
    if (watcher_.joinable())
        watcher_.join();

    stopAnalyzer();

    delete this;
    return 0;
}

HRESULT VDJ_API CVDJDeskBridgeManual::OnStart()
{
    if (running_)
        return S_OK;

    lastSignature1_.clear();
    lastSignature2_.clear();

    running_ = true;
    watcher_ = std::thread(&CVDJDeskBridgeManual::watcherLoop, this);

    // Avvia il motore esterno SOLO quando il plugin e' ON.
    startAnalyzer();

    return S_OK;
}

HRESULT VDJ_API CVDJDeskBridgeManual::OnStop()
{
    running_ = false;

    if (watcher_.joinable())
        watcher_.join();

    stopAnalyzer();

    return S_OK;
}

HRESULT VDJ_API CVDJDeskBridgeManual::OnProcessSamples(float *buffer, int nb)
{
    // Pure pass-through. Audio is untouched.
    (void)buffer;
    (void)nb;
    return S_OK;
}
