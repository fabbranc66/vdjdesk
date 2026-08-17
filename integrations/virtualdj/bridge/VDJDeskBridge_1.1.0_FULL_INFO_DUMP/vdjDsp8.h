#pragma once
#ifndef VdjDsp8H
#define VdjDsp8H
#include "vdjPlugin8.h"

class IVdjPluginDsp8 : public IVdjPlugin8
{
public:
    virtual HRESULT VDJ_API OnStart() {return S_OK;}
    virtual HRESULT VDJ_API OnStop() {return S_OK;}
    virtual HRESULT VDJ_API OnProcessSamples(float *buffer, int nb)=0;

    int SampleRate;
    int SongBpm;
    double SongPosBeats;
};

static const GUID IID_IVdjPluginDsp8 =
{ 0x7cfcf3f5, 0x6fb9, 0x434c, { 0xb6, 0x03, 0xd7, 0x3a, 0x88, 0xf6, 0x72, 0x26 } };

#endif
