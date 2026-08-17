#pragma once
#include "vdjDsp8.h"

#include <atomic>
#include <mutex>
#include <string>
#include <thread>

class CVDJDeskBridgeManual : public IVdjPluginDsp8
{
public:
    HRESULT VDJ_API OnLoad() override;
    HRESULT VDJ_API OnGetPluginInfo(TVdjPluginInfo8 *infos) override;
    ULONG VDJ_API Release() override;

    HRESULT VDJ_API OnStart() override;
    HRESULT VDJ_API OnStop() override;
    HRESULT VDJ_API OnProcessSamples(float *buffer, int nb) override;

private:
    struct DeckState
    {
        int deck = 0;
        std::string title;
        std::string artist;
        std::string filepath;
        double bpm = 0.0;
        double position = 0.0;
        bool hasLyrics = false;
        std::string lyricsLanguage;
    };

    std::atomic<bool> running_{false};
    std::thread watcher_;
    std::mutex ioMutex_;

    PROCESS_INFORMATION analyzerProcess_{};

    std::string lastSignature1_;
    std::string lastSignature2_;

    std::string baseDir() const;
    std::string logPath() const;
    std::string currentLogPath() const;
    std::string statePath() const;

    std::string getStringSafe(const std::string& command);
    double getNumberSafe(const std::string& command, double fallback = 0.0);
    bool getBoolSafe(const std::string& command);

    DeckState readDeck(int deck);
    std::string signature(const DeckState& d) const;

    void watcherLoop();
    void processIfChanged(bool force = false);

    std::string pluginDirectory() const;
    bool startAnalyzer();
    void stopAnalyzer();
    void writeState(const DeckState& d1, const DeckState& d2);
    void writeHistoricalLog(const DeckState& d1, const DeckState& d2);
    void writeCurrentLog(const DeckState& d1, const DeckState& d2);
};
