//
//  LabDirectionMiniMap.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-13.
//


import SwiftUI

// MARK: - LabDirectionMiniMap


struct LabDirectionMiniMap: View {

    /// Pass the room name matching one of the ClinicRoom entries (e.g. "Laboratory", "Blood Test")
    var targetRoomName: String = "Laboratory"

    @State private var pathProgress: CGFloat = 0
    @State private var glowPhase: CGFloat  = 0
    @State private var dotScale: CGFloat   = 1

    private var floor: ClinicFloor {
        // Lab-related rooms are on the upper floor
        let upperNames = ClinicRoom.upper.map { $0.name }
        return upperNames.contains(targetRoomName) ? .upper : .ground
    }

    private var rooms: [ClinicRoom] {
        floor == .ground ? ClinicRoom.ground : ClinicRoom.upper
    }

    private var targetRoom: ClinicRoom? {
        rooms.first { $0.name == targetRoomName }
    }

    private var entranceRoom: ClinicRoom? {
        rooms.first { $0.isEntrance }
    }

    var body: some View {
        GeometryReader { geo in
            let size = CGSize(width: geo.size.width, height: geo.size.height)
            ZStack {
                
                RoundedRectangle(cornerRadius: 16)
                    .fill(Color(hex: "0A1628"))

       
                Canvas { ctx, sz in
                    let step: CGFloat = 16
                    var x: CGFloat = 0
                    while x < sz.width {
                        var p = Path()
                        p.move(to: CGPoint(x: x, y: 0))
                        p.addLine(to: CGPoint(x: x, y: sz.height))
                        ctx.stroke(p, with: .color(Color.white.opacity(0.04)), lineWidth: 0.5)
                        x += step
                    }
                    var y: CGFloat = 0
                    while y < sz.height {
                        var p = Path()
                        p.move(to: CGPoint(x: 0, y: y))
                        p.addLine(to: CGPoint(x: sz.width, y: y))
                        ctx.stroke(p, with: .color(Color.white.opacity(0.04)), lineWidth: 0.5)
                        y += step
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 16))

                ForEach(rooms) { room in
                    let sr = scaled(room.rect, in: size)
                    let isTarget   = room.name == targetRoomName
                    let isEntrance = room.isEntrance

                    ZStack {
                        RoundedRectangle(cornerRadius: 6)
                            .fill(
                                isTarget
                                    ? room.accent.opacity(0.22)
                                    : isEntrance
                                        ? Color(hex: "0DC8A4").opacity(0.10)
                                        : Color.white.opacity(0.04)
                            )
                            .frame(width: sr.width, height: sr.height)

                        RoundedRectangle(cornerRadius: 6)
                            .stroke(
                                isTarget
                                    ? room.accent
                                    : isEntrance
                                        ? Color(hex: "0DC8A4").opacity(0.7)
                                        : Color.white.opacity(0.14),
                                lineWidth: isTarget ? 1.8 : 0.8
                            )
                            .frame(width: sr.width, height: sr.height)

               
                        if isTarget {
                            RoundedRectangle(cornerRadius: 6)
                                .stroke(room.accent.opacity(0.25), lineWidth: 6)
                                .blur(radius: 5)
                                .frame(width: sr.width, height: sr.height)
                        }

                        VStack(spacing: 2) {
                            Image(systemName: room.icon)
                                .font(.system(size: 9, weight: .semibold))
                                .foregroundColor(
                                    isTarget
                                        ? room.accent
                                        : isEntrance
                                            ? Color(hex: "0DC8A4")
                                            : Color.white.opacity(0.45)
                                )
                            Text(room.name)
                                .font(.system(size: 6.5, weight: .bold))
                                .foregroundColor(isTarget ? .white : Color.white.opacity(0.45))
                                .multilineTextAlignment(.center)
                                .lineLimit(2)
                        }
                        .frame(width: sr.width - 6, height: sr.height - 4)
                    }
                    .position(x: sr.midX, y: sr.midY)
                }

    
                if let dest = targetRoom, let from = entranceRoom {
                    let path = navPath(from: from, to: dest, in: size)

   
                    path
                        .stroke(dest.accent.opacity(0.18), lineWidth: 8)
                        .blur(radius: 5)


                    path
                        .trim(from: 0, to: pathProgress)
                        .stroke(
                            Color(hex: "0DC8A4"),
                            style: StrokeStyle(lineWidth: 2, lineCap: .round, dash: [6, 4])
                        )
                        .animation(.easeInOut(duration: 1.1), value: pathProgress)

            
                    Circle()
                        .fill(dest.accent)
                        .frame(width: 11, height: 11)
                        .overlay(Circle().stroke(Color.white, lineWidth: 1.8))
                        .shadow(color: dest.accent, radius: 5)
                        .scaleEffect(dotScale)
                        .position(center(of: dest, in: size))
                }

          
                if let from = entranceRoom {
                    ZStack {
                        Circle()
                            .fill(Color(hex: "0DC8A4").opacity(0.2))
                            .frame(width: 20, height: 20)
                            .scaleEffect(1 + 0.45 * sin(glowPhase))
                        Circle()
                            .fill(Color(hex: "0DC8A4"))
                            .frame(width: 9, height: 9)
                            .overlay(Circle().stroke(Color.white, lineWidth: 1.5))
                    }
                    .position(center(of: from, in: size))
                }

                
                Text("— CORRIDOR —")
                    .font(.system(size: 6, weight: .bold))
                    .tracking(2)
                    .foregroundColor(Color.white.opacity(0.18))
                    .position(x: size.width / 2, y: size.height * 0.135)

               
                VStack {
                    Spacer()
                    HStack(spacing: 10) {
                        legendDot(color: Color(hex: "0DC8A4"), pulsing: true)
                        Text("You")
                            .font(.system(size: 9, weight: .semibold))
                            .foregroundColor(.white.opacity(0.7))

                        legendDot(color: targetRoom?.accent ?? .white, pulsing: false)
                        Text(targetRoomName)
                            .font(.system(size: 9, weight: .semibold))
                            .foregroundColor(.white.opacity(0.7))

                        Spacer()

                        if let mins = targetRoom?.walkMins {
                            HStack(spacing: 3) {
                                Image(systemName: "figure.walk")
                                    .font(.system(size: 9))
                                Text("\(mins) min")
                                    .font(.system(size: 9, weight: .bold))
                            }
                            .foregroundColor(Color(hex: "0DC8A4"))
                        }
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 7)
                    .background(Color(hex: "0A1628").opacity(0.92))
                }
            }
            .clipShape(RoundedRectangle(cornerRadius: 16))
            .overlay(
                RoundedRectangle(cornerRadius: 16)
                    .stroke(Color.white.opacity(0.08), lineWidth: 1)
            )
        }
        .onAppear {

            withAnimation(.easeInOut(duration: 1.1)) {
                pathProgress = 1
            }
 
            withAnimation(.linear(duration: 2).repeatForever(autoreverses: false)) {
                glowPhase = .pi * 2
            }

            withAnimation(.easeInOut(duration: 0.8).repeatForever(autoreverses: true)) {
                dotScale = 1.2
            }
        }
    }



    private func scaled(_ r: CGRect, in size: CGSize) -> CGRect {
        CGRect(
            x: r.minX * size.width,
            y: r.minY * size.height,
            width: r.width * size.width,
            height: r.height * size.height
        )
    }

    private func center(of room: ClinicRoom, in size: CGSize) -> CGPoint {
        let sr = scaled(room.rect, in: size)
        return CGPoint(x: sr.midX, y: sr.midY)
    }

    private func navPath(from: ClinicRoom, to: ClinicRoom, in size: CGSize) -> Path {
        let s = center(of: from, in: size)
        let e = center(of: to, in: size)
        let corridorY = size.height * 0.135

        var p = Path()
        p.move(to: s)
        p.addLine(to: CGPoint(x: s.x, y: corridorY))
        p.addLine(to: CGPoint(x: e.x, y: corridorY))
        p.addLine(to: e)
        return p
    }

    private func legendDot(color: Color, pulsing: Bool) -> some View {
        ZStack {
            if pulsing {
                Circle().fill(color.opacity(0.3)).frame(width: 10, height: 10)
            }
            Circle().fill(color).frame(width: 6, height: 6)
        }
    }
}



#Preview {
    ScrollView {
        VStack(spacing: 24) {
            Text("Lab Direction Map")
                .font(.headline)

    
            LabDirectionMiniMap(targetRoomName: "Laboratory")
                .frame(height: 200)
                .padding(.horizontal, 20)

            // Blood Test
            LabDirectionMiniMap(targetRoomName: "Blood Test")
                .frame(height: 200)
                .padding(.horizontal, 20)

      
            LabDirectionMiniMap(targetRoomName: "Pathology")
                .frame(height: 200)
                .padding(.horizontal, 20)
        }
        .padding(.vertical, 24)
    }
}
